<?php

use Michaelrk02\OmPhp\Client;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private $client;

    protected function setUp(): void
    {
        $this->client = new Client(OM_SERVER_URL, OM_SECRET_KEY);
    }

    public function testStore()
    {
        $objectId = $this->client->store('test', 'example.txt');
        $this->assertTrue($objectId !== false);
        $this->assertTrue($objectId !== '');

        $objectUrl = $this->client->getUrl($objectId);
        $this->assertTrue($objectUrl !== false);
        $this->assertStringStartsWith('http', $objectUrl);

        $downloaded = $this->client->fetch($objectId);
        $this->assertFileEquals('example.txt', $downloaded);

        return $objectId;
    }

    /**
     * @depends testStore
     */
    public function testDelete($objectId)
    {
        $result = $this->client->delete($objectId);
        $this->assertTrue($result);

        $objectUrl = $this->client->getUrl($objectId);
        $this->assertTrue($objectUrl === false);
    }

    public function testPublicAccess()
    {
        $objectId = $this->client->store('test', 'example.txt', ['access' => 'public']);
        $objectUrl = $this->client->getUrl($objectId);
        $this->assertStringContainsString('id', $objectUrl);

        $this->client->delete($objectId);

        $this->assertTrue(true);
    }

    public function testProtectedAccess()
    {
        $objectId = $this->client->store('test', 'example.txt', ['access' => 'protected', 'ttl' => 1]);
        $objectUrl = $this->client->getUrl($objectId);
        $this->assertStringContainsString('time', $objectUrl);
        $this->assertStringContainsString('id', $objectUrl);
        $this->assertStringContainsString('signature', $objectUrl);

        // test expiration
        sleep(2);
        $request = curl_init($objectUrl);
        curl_exec($request);
        $status = (int)curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        $this->assertEquals($status, 401);

        $this->client->delete($objectId);
    }
}
