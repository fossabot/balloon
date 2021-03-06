<?php
namespace Balloon\Testsuite\Api\Collection;

use Balloon\Testsuite\Test;

class MoveTest extends Test
{
    protected static $delta = [];
    protected static $first_cursor;
    protected static $current_cursor;

    public function testReceiveLastDelta()
    {
        self::$first_cursor = $this->getLastCursor();
        self::$current_cursor = self::$first_cursor;
    }

    public function testCreate()
    {
        $name = uniqid();
        $res = $this->request('POST', '/collection?name='.$name);
        $this->assertEquals(201, $res->getStatusCode());
        $body = $this->jsonBody($res);
        $id = new \MongoDB\BSON\ObjectID($body);
        $this->assertInstanceOf('\MongoDB\BSON\ObjectID', $id);

        $delta = $this->getLastDelta(self::$current_cursor);
        $this->assertCount(1, $delta['nodes']);
        $this->assertEquals((string)$id, $delta['nodes'][0]->id);
        self::$current_cursor = $delta['cursor'];
        self::$delta[] = [
            'id'        => (string)$id,
            'deleted'   => false,
            'path'      => '/'.$name
        ];
        
        return [
            'id'  => $id,
            'name'=> $name
        ];
    }

    public function testCreate2()
    {
        return $this->testCreate();
    }
    
    /**
     * @depends testCreate
     */
    public function testMoveCollectionIntoItself($node)
    {
        $res = $this->request('POST', '/collection/move?id='.$node['id'].'&destid='.$node['id']);
        $this->assertEquals(400, $res->getStatusCode());
        $body = $this->jsonBody($res);
        $this->assertEquals('Balloon\\Exception\\Conflict', $body['error']);
    }
    
    /**
     * @depends testCreate
     */
    public function testMoveCollectionIntoSameParent($node)
    {
        $res = $this->request('POST', '/collection/'.$node['id'].'/move?destid=');
        $this->assertEquals(400, $res->getStatusCode());
        $body = $this->jsonBody($res);
        $this->assertEquals('Balloon\\Exception\\Conflict', $body['error']);
    }

    /**
     * @depends testCreate
     * @depends testCreate2
     */
    public function testMoveCollectionIntoOtherCollection($source, $dest)
    {
        $res = $this->request('POST', '/collection/move?id='.$source['id'].'&destid='.$dest['id']);
        $this->assertEquals(204, $res->getStatusCode());
        
        $delta = $this->getLastDelta(self::$current_cursor);
        $this->assertCount(2, $delta['nodes']);
        $this->assertEquals((string)$source['id'], $delta['nodes'][0]->id);
        $this->assertEquals((string)$source['id'], $delta['nodes'][1]->id);
        self::$current_cursor = $delta['cursor'];

        self::$delta[0]['path'] = '/'.$dest['name'].'/'.$source['name'];
        self::$delta[] = [
            'id'      => (string)$source['id'],
            'deleted' => true,
            'path'    => '/'.$source['name'] 
        ];

        return [
            'child' => $source,
            'parent'=> $dest,
        ];
    }

    /**
     * @depends testMoveCollectionIntoOtherCollection
     */
    public function testMoveParentIntoChild($nodes)
    {
        $res = $this->request('POST', '/collection/'.$nodes['parent']['id'].'/move?destid='.$nodes['child']['id']);
        $this->assertEquals(400, $res->getStatusCode());
        $body = $this->jsonBody($res);
        $this->assertEquals('Balloon\\Exception\\Conflict', $body['error']);
    }

    public function testCreateA()
    {
        $name = uniqid();
        $res = $this->request('POST', '/collection?name='.$name);
        $this->assertEquals(201, $res->getStatusCode());
        $body = $this->jsonBody($res);
        $id = new \MongoDB\BSON\ObjectID($body);
        $this->assertInstanceOf('\MongoDB\BSON\ObjectID', $id);
        
        $delta = $this->getLastDelta(self::$current_cursor);
        $this->assertCount(1, $delta['nodes']);
        $this->assertEquals((string)$id, $delta['nodes'][0]->id);
        self::$current_cursor = $delta['cursor'];
        
        self::$delta[] = [
            'id'        => (string)$id,
            'deleted'   => false,
            'path'      => '/'.$name
        ];

        return [
            'id'   => $id,
            'name' => $name,
        ];
    }
    
    /**
     * @depends testCreateA
     */
    public function testCreateB($a)
    {
        $name = uniqid();
        $res = $this->request('POST', '/collection?name='.$name);
        $this->assertEquals(201, $res->getStatusCode());
        $body = $this->jsonBody($res);
        $id = new \MongoDB\BSON\ObjectID($body);
        $this->assertInstanceOf('\MongoDB\BSON\ObjectID', $id);
        
        $delta = $this->getLastDelta(self::$current_cursor);
        $this->assertCount(1, $delta['nodes']);
        $this->assertEquals((string)$id, $delta['nodes'][0]->id);
        self::$current_cursor = $delta['cursor'];
        
        self::$delta[] = [
            'id'        => (string)$id,
            'deleted'   => false,
            'path'      => '/'.$name
        ];
        
        return [
            'a' => $a,
            'b' => [
                'id'   => $id,
                'name' => $name,
            ]
        ];
    }
    
    /**
     * @depends testCreateB
     */
    public function testCreateAUnderB($nodes)
    {
        $res = $this->request('POST', '/collection/'.$nodes['b']['id'].'?name='.$nodes['a']['name']);
        $this->assertEquals(201, $res->getStatusCode());
        $body = $this->jsonBody($res);
        $id = new \MongoDB\BSON\ObjectID($body);
        $this->assertInstanceOf('\MongoDB\BSON\ObjectID', $id);
        
        $delta = $this->getLastDelta(self::$current_cursor);
        $this->assertCount(1, $delta['nodes']);
        $this->assertEquals((string)$id, $delta['nodes'][0]->id);
        self::$current_cursor = $delta['cursor'];
        
        self::$delta[] = [
            'id'        => (string)$id,
            'deleted'   => false,
            'path'      => '/'.$nodes['b']['name'].'/'.$nodes['a']['name']
        ];

        $nodes['a2'] = [
            'id'   => $id,
            'name' => $nodes['a']['name']
        ];

        return $nodes;
    }

    /**
     * @depends testCreateAUnderB
     */
    public function testMoveAToBConflict($nodes)
    {
        $res = $this->request('POST', '/collection/'.$nodes['a']['id'].'/move?destid='.$nodes['b']['id']);
        $this->assertEquals(400, $res->getStatusCode());
        $body = $this->jsonBody($res);
        $this->assertEquals('Balloon\\Exception\\Conflict', $body['error']);
        return $nodes;
    }

    /**
     * @depends testMoveAToBConflict
     */
    public function testMoveAToBResolvedConflictMerge($nodes)
    {
        $res = $this->request('POST', '/collection/'.$nodes['a']['id'].'/move?destid='.$nodes['b']['id'].'&conflict=2');
        $this->assertEquals(204, $res->getStatusCode());
        $delta = $this->getLastDelta(self::$current_cursor);

        $this->assertCount(2, $delta['nodes']);
        $path_a_under_b = '/'.$nodes['b']['name'].'/'.$nodes['a']['name'];        

        $this->assertEquals((string)$nodes['a2']['id'], $delta['nodes'][0]->id);
        $this->assertEquals($path_a_under_b, $delta['nodes'][0]->path);
        $this->assertFalse($delta['nodes'][0]->deleted);

        $this->assertEquals((string)$nodes['a']['id'], $delta['nodes'][1]->id);
        $this->assertEquals('/'.$nodes['a']['name'], $delta['nodes'][1]->path);
        $this->assertTrue($delta['nodes'][1]->deleted);

        self::$current_cursor = $delta['cursor'];
        self::$delta[3]['deleted'] = true;
    }

    public function testMoveAToBResolvedConflictRename()
    {
        $nodes = $this->testCreateAUnderB($this->testCreateB($this->testCreateA()));
        $res = $this->request('POST', '/collection/'.$nodes['a']['id'].'/move?destid='.$nodes['b']['id'].'&conflict=1');
        $this->assertEquals(200, $res->getStatusCode());
        $body = $this->jsonBody($res);

        $delta = $this->getLastDelta(self::$current_cursor);
        $this->assertCount(3, $delta['nodes']);

        $this->assertEquals((string)$nodes['a']['id'], $delta['nodes'][0]->id);
        $this->assertEquals('/'.$nodes['a']['name'], $delta['nodes'][0]->path);
        $this->assertTrue($delta['nodes'][0]->deleted);
        
        $this->assertEquals((string)$nodes['a']['id'], $delta['nodes'][1]->id);
        $this->assertEquals('/'.$body, $delta['nodes'][2]->path);
        $this->assertTrue($delta['nodes'][2]->deleted);

        $this->assertEquals((string)$nodes['a']['id'], $delta['nodes'][1]->id);
        $this->assertEquals('/'.$nodes['b']['name'].'/'.$body, $delta['nodes'][1]->path);
        $this->assertFalse($delta['nodes'][1]->deleted);
        
        self::$delta[] = [
            'id'        => (string)$nodes['a']['id'],
            'deleted'   => true,
            'path'      => self::$delta[6]['path']
        ];

        self::$delta[6]['path'] =  '/'.$nodes['b']['name'].'/'.$body;

        self::$delta[] = [
            'id'        => (string)$nodes['a']['id'],
            'deleted'   => true,
            'path'      => '/'.$body
        ];
    }

    public function testDelta()
    {
        $delta = $this->getLastDelta(self::$first_cursor);
        $this->assertCount(count(self::$delta), $delta['nodes']);

        foreach($delta['nodes'] as $key => $node) {
            $node = (array)$node;
            $this->assertEquals($node['id'], self::$delta[$key]['id']);
            $this->assertEquals($node['path'], self::$delta[$key]['path']);
            $this->assertEquals($node['deleted'], self::$delta[$key]['deleted']);

            if(array_key_exists('directoy', $node)) {
                $this->assertTrue($node['directory']);
            }
        }
    }
}
