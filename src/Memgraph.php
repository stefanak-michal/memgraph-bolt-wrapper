<?php

use Bolt\Bolt;
use Bolt\connection\{Socket, StreamSocket};
use Bolt\protocol\{AProtocol, Response, V4_3, V4_1, V4, V3};

/**
 * Class Memgraph - adapter for Bolt library
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/memgraph-bolt-wrapper
 */
class Memgraph
{
    /**
     * Assigned handler is called every time query is executed
     * @var callable (string $query, array $params = [], float $executionTime = 0, array $statistics = [])
     */
    public static $logHandler;

    /**
     * Provided handler is invoked on Exception instead of trigger_error
     * @var callable (Exception $e)
     */
    public static $errorHandler;

    /**
     * Set your authentification
     * @see \Bolt\helpers\Auth
     */
    public static array $auth;

    public static string $host = '127.0.0.1';
    public static int $port = 7687;
    public static float $timeout = 15;

    private static AProtocol|V4_3|V4_1|V4|V3|null $protocol = null;
    private static array $statistics;

    /**
     * Get connection protocol for bolt communication
     */
    protected static function getProtocol(): AProtocol|V4_3|V4_1|V4|V3
    {
        if (is_null(self::$protocol)) {
            try {
                if (self::$host != '127.0.0.1') {
                    $conn = new StreamSocket(self::$host, self::$port, self::$timeout);
                    $conn->setSslContextOptions([
                        'peer_name' => 'Memgraph DB',
                        'allow_self_signed' => true,
                    ]);
                } else {
                    $conn = new Socket(self::$host, self::$port, self::$timeout);
                }

                $bolt = new Bolt($conn);
                self::$protocol = $bolt->setProtocolVersions(5.2, 4.3, 4.1, 4.0)->build();
                self::$protocol->hello(self::$auth);

                register_shutdown_function(function () {
                    try {
                        if (method_exists(self::$protocol, 'goodbye'))
                            self::$protocol->goodbye();
                    } catch (Exception $e) {
                    }
                });
            } catch (Exception $e) {
                self::handleException($e);
            }
        }

        return self::$protocol;
    }

    /**
     * Return full output
     * @link https://www.neo4j.com/docs/bolt/current/bolt/message/#messages-run
     * @param string $query
     * @param array $params
     * @param array $extra
     * @return array
     */
    public static function query(string $query, array $params = [], array $extra = []): array
    {
        $run = $all = null;
        try {
            $runResponse = self::getProtocol()->run($query, $params, $extra)->getResponse();
            if ($runResponse->getSignature() != Response::SIGNATURE_SUCCESS) {
                throw new Exception(implode(' ', $runResponse->getContent()));
            }
            $run = $runResponse->getContent();

            foreach (self::getProtocol()->pull()->getResponses() as $response) {
                if ($response->getSignature() == Response::SIGNATURE_IGNORED || $response->getSignature() == Response::SIGNATURE_FAILURE) {
                    throw new Exception(implode(' ', $runResponse->getContent()));
                }
                $all[] = $response->getContent();
            }
        } catch (Exception $e) {
            self::getProtocol()->reset();
            self::handleException($e);
            return [];
        }

        $last = array_pop($all);

        self::$statistics = $last['stats'] ?? [];
        self::$statistics['rows'] = count($all);

        if (is_callable(self::$logHandler)) {
            $time = 0;
            foreach ($last as $key => $value) {
                if (substr($key, -5) == '_time') {
                    $time += $value;
                }
            }
            call_user_func(self::$logHandler, $query, $params, $time, self::$statistics);
        }

        return !empty($all) ? array_map(function ($element) use ($run) {
            return array_combine($run['fields'], $element);
        }, $all) : [];
    }

    /**
     * Get first value from first row
     * @param string $query
     * @param array $params
     * @param array $extra
     * @return mixed
     */
    public static function queryFirstField(string $query, array $params = [], array $extra = []): mixed
    {
        $data = self::query($query, $params, $extra);
        if (empty($data)) {
            return null;
        }
        return reset($data[0]);
    }

    /**
     * Get first values from all rows
     * @param string $query
     * @param array $params
     * @param array $extra
     * @return array
     */
    public static function queryFirstColumn(string $query, array $params = [], array $extra = []): array
    {
        $data = self::query($query, $params, $extra);
        if (empty($data)) {
            return [];
        }
        $key = key($data[0]);
        return array_map(function ($element) use ($key) {
            return $element[$key];
        }, $data);
    }

    /**
     * Begin transaction
     * @link https://www.neo4j.com/docs/bolt/current/bolt/message/#messages-begin
     * @param array $extra
     * @return bool
     */
    public static function begin(array $extra = []): bool
    {
        try {
            $response = self::getProtocol()->begin($extra)->getResponse();
            if ($response->getSignature() != Response::SIGNATURE_SUCCESS) {
                throw new Exception(implode(' ', $response->getContent()));
            }
            if (is_callable(self::$logHandler)) {
                call_user_func(self::$logHandler, 'BEGIN TRANSACTION');
            }
            return true;
        } catch (Exception $e) {
            self::getProtocol()->reset();
            self::handleException($e);
        }
        return false;
    }

    /**
     * Commit transaction
     * @link https://www.neo4j.com/docs/bolt/current/bolt/message/#messages-commit
     * @return bool
     */
    public static function commit(): bool
    {
        try {
            $response = self::getProtocol()->commit()->getResponse();
            if ($response->getSignature() != Response::SIGNATURE_SUCCESS) {
                throw new Exception(implode(' ', $response->getContent()));
            }
            if (is_callable(self::$logHandler)) {
                call_user_func(self::$logHandler, 'COMMIT TRANSACTION');
            }
            return true;
        } catch (Exception $e) {
            self::getProtocol()->reset();
            self::handleException($e);
        }
        return false;
    }

    /**
     * Rollback transaction
     * @link https://www.neo4j.com/docs/bolt/current/bolt/message/#messages-rollback
     * @return bool
     */
    public static function rollback(): bool
    {
        try {
            $response = self::getProtocol()->rollback()->getResponse();
            if ($response->getSignature() != Response::SIGNATURE_SUCCESS) {
                throw new Exception(implode(' ', $response->getContent()));
            }
            if (is_callable(self::$logHandler)) {
                call_user_func(self::$logHandler, 'ROLLBACK TRANSACTION');
            }
            return true;
        } catch (Exception $e) {
            self::getProtocol()->reset();
            self::handleException($e);
        }
        return false;
    }

    /**
     * Return statistic info from last executed query
     *
     * Possible keys:
     * <pre>
     * nodes-created
     * nodes-deleted
     * properties-set
     * relationships-created
     * relationship-deleted
     * labels-added
     * labels-removed
     * indexes-added
     * indexes-removed
     * constraints-added
     * constraints-removed
     * </pre>
     *
     * @param string $key
     * @return int
     */
    public static function statistic(string $key): int
    {
        return intval(self::$statistics[$key] ?? 0);
    }

    /**
     * @param Exception $e
     */
    private static function handleException(Exception $e): void
    {
        if (is_callable(self::$errorHandler)) {
            call_user_func(self::$errorHandler, $e);
            return;
        }

        trigger_error('Database error occured: ' . $e->getMessage(), E_USER_ERROR);
    }

}
