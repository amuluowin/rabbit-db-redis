<?php
declare(strict_types=1);

namespace Rabbit\DB\Redis;

use Predis\Client;
use Rabbit\Base\App;
use Rabbit\Base\Core\Exception;
use Rabbit\Base\Helper\ArrayHelper;
use Rabbit\DB\Redis\Commands\ClusterEVAL;
use Rabbit\DB\Redis\Commands\EXEC;
use Rabbit\DB\Redis\Commands\MUTIL;
use Rabbit\Pool\AbstractConnection;
use Rabbit\Pool\PoolManager;
use Throwable;

/**
 * Class Predis
 * @package rabbit\db\redis
 */
class Predis extends AbstractConnection
{
    use ClusterTrait;

    /** @var Client */
    private ?Client $conn = null;

    public function createConnection(): void
    {
        $pool = PoolManager::getPool($this->poolKey);
        $address = $pool->getServiceList(true);
        $currAddr = current($address);
        $config = $this->parseUri($currAddr);
        if (isset($config['sentinel'])) {
            $config['replication'] = 'sentinel';
            $config['service'] = ArrayHelper::remove($config, 'master', 'mymaster');
            unset($config['sentinel']);
        } else {
            $address = count($address) === 1 ? $currAddr : $address;
        }
        $this->conn = new Client($address, $config);
        if (isset($config['cluster'])) {
            $this->cluster = true;
            $profile = $this->conn->getProfile();
            $profile->defineCommand('MULTI', MUTIL::class);
            $profile->defineCommand('EXEC', EXEC::class);
            $profile->defineCommand('EVAL', ClusterEVAL::class);
        }
    }

    /**
     * @param string $uri
     * @return array
     * @throws Exception
     */
    protected function parseUri(string $uri): array
    {
        $parseAry = parse_url($uri);
        if (!isset($parseAry['host']) || !isset($parseAry['port'])) {
            $error = sprintf(
                'Redis Connection format is incorrect uri=%s, eg:tcp://127.0.0.1:6379/1?password=password',
                $uri
            );
            throw new Exception($error);
        }
        $query = $parseAry['query'] ?? '';
        parse_str($query, $options);
        $options['parameters']['password'] = ArrayHelper::remove($options, 'password', null);
        (isset($parseAry['path']) && !isset($options['cluster'])) && $options['parameters']['database'] = (int)str_replace('/', '', $parseAry['path']);
        return $options;
    }

    /**
     * @param string $name
     * @param array $params
     * @return mixed
     */
    public function executeCommand(string $name, array $params = [])
    {
        return $this->conn->$name(...$params);
    }

    /**
     * @throws Throwable
     */
    public function reconnect(): void
    {
        App::warning("predis reconnecting...");
        $this->createConnection();
    }
}