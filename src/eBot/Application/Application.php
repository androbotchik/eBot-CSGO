<?php

/**
 * eBot - A bot for match management for CS:GO
 * @license     http://creativecommons.org/licenses/by/3.0/ Creative Commons 3.0
 * @author      Julien Pardons <julien.pardons@esport-tools.net>
 * @version     3.0
 * @date        21/10/2012
 */

namespace eBot\Application;

use eBot\Manager\ReportManager;
use eTools\Utils\Logger;
use eTools\Application\AbstractApplication;
use eTools\Socket\UDPSocket as Socket;
use eBot\Manager\MessageManager;
use eBot\Manager\PluginsManager;
use eBot\Manager\MatchManager;
use eBot\Config\Config;

class Application extends AbstractApplication
{

    const VERSION = "3.4";

    private $socket = null;
    public $websocket = null;
    private $clientsConnected = false;
    public $db;

    public function run()
    {
        // Loading Logger instance
        Logger::getInstance();
        Logger::log($this->getName());

        // Initializing database
        $this->initDatabase();

        // Loading eBot configuration
        Logger::log("Loading config");
        Config::getInstance()->scanAdvertising();
        Config::getInstance()->printConfig();

        // Registring components
        Logger::log("Registering MatchManager");
        MatchManager::getInstance();

        Logger::log("Registering Messages");
        MessageManager::createFromConfigFile();

        Logger::log("Registering PluginsManager");
        PluginsManager::getInstance();

        Logger::log("Registering ReportManager");
        ReportManager::getInstance();

        // Starting application
        Logger::log("Starting eBot Application");

        /* try {
          $this->socket = new Socket(Config::getInstance()->getBot_ip(), Config::getInstance()->getBot_port());
          } catch (Exception $ex) {
          Logger::error("Unable to bind socket");
          die();
          } */

        try {
            $this->websocket['match'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/match");
            $this->websocket['match']->open();
            $this->websocket['rcon'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/rcon");
            $this->websocket['rcon']->open();
            $this->websocket['logger'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/logger");
            $this->websocket['logger']->open();
            $this->websocket['livemap'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/livemap");
            $this->websocket['livemap']->open();
            $this->websocket['aliveCheck'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/alive");
            $this->websocket['aliveCheck']->open();
        } catch (Exception $ex) {
            Logger::error("Unable to create Websocket.");
            die();
        }

        PluginsManager::getInstance()->startAll();

        $config = \eBot\Config\Config::getInstance();

        $redis = new \Redis();
        $redis->connect(
            $config->getRedisHost(),
            $config->getRedisPort(),
            1,
            null,
            0,
            0,
            $config->getRedisAuthUsername() ? ['auth' => [$config->getRedisAuthUsername(), $config->getRedisAuthPassword()]] : []
        );

        $time = time();
        $timePub = time();
        while (true) {
            $ip = "";

            $data = $redis->lPop($config->getRedisChannelEbotFromWs());
            if (!$data) {
                $data = $redis->lPop($config->getRedisChannelLog());
                if ($data) {
                    $d = explode("---", $data);
                    $ip = array_shift($d);
                    $data = implode("---", $d);
                }
            }

            if (!$data) {
                usleep(50000);
            }
            //$data = $this->socket->recvfrom($ip);

            if ($data) {
                if (!preg_match("/\d+\/\d+\/\d+/", $data)) {
                    if ($data == '__true__') {
                        $this->clientsConnected = true;
                    } else if ($data == '__false__') {
                        $this->clientsConnected = false;
                    } else if ($data == '__aliveCheck__') {
                        $this->websocket['aliveCheck']->sendData('__isAlive__');
                    } else {
                        Logger::log("DEBUG: Получено из Redis (сырая строка): " . $data);
                                // --- НОВОЕ ИЗМЕНЕНИЕ ЗДЕСЬ ---
                        // Удаляем внешние кавычки, если они есть. str_starts_with и str_ends_with требуют PHP 8.0+.
                        // Если у тебя более старая версия PHP, используй substr или trim.
                        $data_cleaned = trim($data, '"');
                        $data_cleaned = stripslashes($data_cleaned);
                        Logger::log("DEBUG: Строка очищена от внешних кавычек: " . $data_cleaned);                        
                        // --- КОНЕЦ ИЗМЕНЕНИЯ ---

                        $data = json_decode($data_cleaned, true);
                        Logger::log("DEBUG: Декодировано в PHP (var_export): " . var_export($data, true));
                        $authkey = \eBot\Manager\MatchManager::getInstance()->getAuthkey($data[1]);
                        Logger::log("DEBUG: Authkey получен: " . $authkey);
                        $text = \eTools\Utils\Encryption::decrypt($data[0], $authkey, 256);
                        Logger::log("DEBUG: Расшифрованный текст: " . $text);
                        if ($text) {
                            if (preg_match("!^(?<id>\d+) stopNoRs (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                Logger::log("DEBUG: Команда stopNoRs найдена: " . $text);
                                if ($match) {
                                    $reply = $match->adminStopNoRs();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => 'stop', 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) stop (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminStop();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => 'stop', 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) executeCommand (?<ip>\d+\.\d+\.\d+\.\d+\:\d+) (?<command>.*)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminExecuteCommand($preg["command"]);
                                    if ($reply) {
                                        $send = json_encode(['id' => $preg["id"], 'content' => $reply]);
                                        $this->websocket['rcon']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) passknife (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminPassKnife();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) forceknife (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminForceKnife();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) forceknifeend (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminForceKnifeEnd();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) forcestart (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminForceStart();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) stopback (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminStopBack();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) pauseunpause (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminPauseUnpause();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) fixsides (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminFixSides();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) streamerready (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminStreamerReady();
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) goBackRounds (?<ip>\d+\.\d+\.\d+\.\d+\:\d+) (?<round>\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminGoBackRounds($preg['round']);
                                    if ($reply) {
                                        $send = json_encode(['message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]]);
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else if (preg_match("!^(?<id>\d+) skipmap (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminSkipMap();
                                    /* if ($reply) {
                                      $send = json_encode(array('message' => 'button', 'content' => 'stop', 'id' => $preg["id"]));
                                      $this->websocket['match']->sendData($send);
                                      } */
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else {
                                Logger::error($text . " not managed");
                            }
                        }
                    }
                } else {
                    $line = $data;

                    if (\eBot\Manager\MatchManager::getInstance()->getMatch($ip)) {
                        file_put_contents(APP_ROOT . "/logs/$ip", $line, FILE_APPEND);
                        $line = trim(substr($line, 27));

                        \eBot\Manager\MatchManager::getInstance()->getMatch($ip)->processMessage($line);
                        // $line = substr($data, 7, strlen($data) - 8);
                        file_put_contents(Logger::getInstance()->getLogPathAdmin() . "/logs_" . \eBot\Manager\MatchManager::getInstance()->getMatch($ip)->getMatchId() . ".log", $line, FILE_APPEND);
                        if ($this->clientsConnected) {
                            $send = json_encode(['id' => \eBot\Manager\MatchManager::getInstance()->getMatch($ip)->getMatchId(), 'content' => $data . "\r\n"]);
                            $this->websocket['logger']->sendData($send);
                        }
                    }
                }
            }
            if ($time + 5 < time()) {
                $time = time();
                $this->websocket['match']->send(json_encode(["message" => "ping"]));
                $this->websocket['logger']->send(json_encode(["message" => "ping"]));
                $this->websocket['rcon']->send(json_encode(["message" => "ping"]));
                $this->websocket['livemap']->send(json_encode(["message" => "ping"]));
                $this->websocket['aliveCheck']->send(json_encode(["message" => "ping"]));
            }

            //if ($nbMessage < 100) {
             if ($timePub + 1 < time()) {
                 $timePub = time();
                 \eBot\Manager\MatchManager::getInstance()->sendPub();
             }
            \eTools\Task\TaskManager::getInstance()->runTask();
            //}
        }
    }

    private function initDatabase()
    {
        $conn = @\mysqli_connect(Config::getInstance()->getMysql_ip() . ':' . Config::getInstance()->getMysql_port(), Config::getInstance()->getMysql_user(), Config::getInstance()->getMysql_pass());
        if (!$conn) {
            Logger::error("Can't login into database " . Config::getInstance()->getMysql_user() . "@" . Config::getInstance()->getMysql_ip());
            die(1);
        }

        if (!\mysqli_select_db($conn, Config::getInstance()->getMysql_base())) {
            Logger::error("Can't select database " . Config::getInstance()->getMysql_base());
            Logger::error(mysqli_error($conn));
            die(1);
        }

        $this->db = $conn;
    }

    public function getName()
    {
        return "eBot CS2 version " . $this->getVersion();
    }

    public function getVersion()
    {
        return self::VERSION;
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function getWebSocket($room)
    {
        return $this->websocket[$room];
    }

}

?>
