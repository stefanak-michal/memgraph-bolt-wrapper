<?php

namespace Memgraph\tests;

use Memgraph;
use Bolt\helpers\Auth;
use Bolt\protocol\IStructure;
use Bolt\protocol\v1\structures\{
    Date,
    Duration,
    LocalTime,
    LocalDateTime
};
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Class MemgraphTest
 * @package Memgraph\tests
 */
class MemgraphTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        Memgraph::$auth = Auth::none();
    }

    /**
     * Basic query test with basic data types
     */
    public function testQuery()
    {
        $params = [
            'number' => 123,
            'string' => 'abc',
            'null' => null,
            'bool' => true,
            'float' => 0.4591563,
            'list' => [1, 2, 3],
            'dictionary' => ['a' => 1, 'b' => 2, 'c' => 3]
        ];

        $query = implode(', ', array_map(function (string $key) {
            return '$' . $key . ' AS ' . $key;
        }, array_keys($params)));

        $result = Memgraph::query('RETURN ' . $query, $params);

        $this->assertCount(1, $result);
        $this->assertEquals($params, $result[0]);
    }

    public function testQueryFirstField()
    {
        $result = Memgraph::queryFirstField('RETURN "First field"');
        $this->assertEquals('First field', $result);
    }

    public function testQueryFirstColumn()
    {
        $result = Memgraph::queryFirstColumn('UNWIND [1, 2, 3] AS n RETURN n');
        $this->assertEquals(3, Memgraph::statistic('rows'));
        $this->assertEquals(range(1, 3), $result);
    }

    public function testTransaction()
    {
        Memgraph::begin();
        $id = Memgraph::queryFirstField('CREATE (t:Test) RETURN ID(t) AS id');
        $this->assertEquals(1, Memgraph::statistic('nodes-created'));
        Memgraph::commit();

        $cnt = Memgraph::queryFirstField('MATCH (t:Test) WHERE ID(t) = $id RETURN count(t)', ['id' => $id]);
        $this->assertEquals(1, $cnt);

        Memgraph::begin();
        Memgraph::query('MATCH (t:Test) WHERE ID(t) = $id DELETE t', ['id' => $id]);
        $this->assertEquals(1, Memgraph::statistic('nodes-deleted'));
        Memgraph::rollback();

        $cnt = Memgraph::queryFirstField('MATCH (t:Test) WHERE ID(t) = $id RETURN count(t)', ['id' => $id]);
        $this->assertEquals(1, $cnt);
    }

    /**
     * Test additional data types
     * @dataProvider structureProvider
     * @param IStructure $structure
     */
    public function testStructure(IStructure $structure)
    {
        $response = Memgraph::queryFirstField('RETURN $s', [
            's' => $structure
        ]);
        $this->assertInstanceOf(get_class($structure), $response);
        $this->assertEquals((string)$structure, (string)$response);
    }

    public function structureProvider(): \Generator
    {
        yield 'Duration' => [new Duration(0, 4, 3, 2)];
        yield 'Date' => [new Date(floor(time() / 86400))];
        yield 'LocalTime' => [new LocalTime(microtime(true))];
        yield 'LocalDateTime' => [new LocalDateTime(time(), 1234)];
    }

    public function testLogHandler()
    {
        Memgraph::$logHandler = function (string $query, array $params = [], float $executionTime = 0, array $statistics = []) {
            $this->assertEquals('MATCH (n) RETURN count(n)', $query);
            $this->assertGreaterThan(0, $executionTime);
            $this->assertEquals([], $params);
            $this->assertEquals(['rows' => 1], $statistics);
        };

        Memgraph::query('MATCH (n) RETURN count(n)');
        Memgraph::$logHandler = null;
    }

    public function testErrorHandler()
    {
        Memgraph::$errorHandler = function (Exception $e) {
            $this->assertStringStartsWith('Memgraph.ClientError.MemgraphError.MemgraphError', $e->getMessage());
        };

        Memgraph::query('Wrong cypher query');
        Memgraph::$errorHandler = null;
    }

    public function testErrorHandlerException()
    {
        Memgraph::$errorHandler = function (Exception $e) {
            throw $e;
        };

        $this->expectException(Exception::class);
        Memgraph::query('Wrong cypher query');
    }
}
