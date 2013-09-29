<?php

class TestBridgeVb extends TestCase {

	public function setUp(BridgeVb $bridge)
	{
		$this->bridge = $bridge;
	}

	public function testBridgeVbDummy()
	{
		$result = $this->bridge->dummy();
		$this->assertEquals('dummy', $result);
	}

}