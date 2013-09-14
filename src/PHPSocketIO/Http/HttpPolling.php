<?php

namespace PHPSocketIO\Http;

use PHPSocketIO\ConnectionInterface;
use PHPSocketIO\Event;
use PHPSocketIO\Protocol\Builder as ProtocolBuilder;
use PHPSocketIO\Response\Response;
use PHPSocketIO\Response\ResponseChunk;
use PHPSocketIO\Response\ResponseChunkStart;
use PHPSocketIO\Response\ResponseChunkEnd;
use PHPSocketIO\Request\Request;
use PHPSocketIO\Protocol\Handshake;

abstract class HttpPolling
{

    /**
     *
     * @var ConnectionInterface
     */
    protected $connection;

    /**
     *
     * @var Request
     */
    protected $request;

    protected $defuleTimeout = 10;

    public function __construct(ConnectionInterface $connection, $sessionInited)
    {
        $this->connection = $connection;
        $this->request = $connection->getRequest();
        if(!$sessionInited){
            $this->init();
            return;
        }
        if($this->request->isMethod('POST')){
            $this->processPOSTMethod();
            return;
        }
        $this->enterPollingMode();
        $this->initEvent();
        $this->connection->setTimeout($this->defuleTimeout, function(){$this->onTimeout();});
        return;
    }

    protected function processPOSTMethod()
    {
        Handshake::processProtocol($this->parseClientEmitData(), $this->connection->getRequest());
        $response = $this->setResponseHeaders(new Response('1'));
        $this->connection->write($response);
    }

    abstract protected function parseClientEmitData();

    protected function initEvent()
    {
        $dispatcher = Event\EventDispatcher::getDispatcher();
        $dispatcher->addListener("server.emit", function(Event\MessageEvent $messageEvent){
            $message = $messageEvent->getMessage();
            $this->writeChunkEnd(ProtocolBuilder::Event(array(
                'name' => $message['event'],
                'args' => array($message['message']),
            )));
        }, $this->connection);
    }

    protected function init()
    {
        $response = $this->setResponseHeaders(
            new Response($this->generateResponseData(ProtocolBuilder::Connect()))
        );
        $this->connection->write($response, true);
    }

    protected function enterPollingMode()
    {
        $response = $this->setResponseHeaders(new ResponseChunkStart());
        $this->connection->write($response);
    }

    abstract protected function generateResponseData($content);

    abstract protected function setResponseHeaders($response);

    protected function onTimeout()
    {
        $this->writeChunkEnd(ProtocolBuilder::Noop());
    }

    protected function writeChunkEnd($content)
    {
        $content = $this->generateResponseData($content);
        $this->connection->clearTimeout();
        $this->connection->write(new ResponseChunk($content));
        $this->connection->write(new ResponseChunkEnd(), true);
    }

}