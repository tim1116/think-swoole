<?php

namespace think\swoole\concerns;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\CloseFrame;
use think\App;
use think\Container;
use think\helper\Str;
use think\swoole\contract\websocket\HandlerInterface;
use think\swoole\contract\websocket\RoomInterface;
use think\swoole\Middleware;
use think\swoole\Websocket;
use think\swoole\websocket\message\PushMessage;
use think\swoole\websocket\Room;

/**
 * Trait InteractsWithWebsocket
 * @package think\swoole\concerns
 *
 * @property App $app
 * @property Container $container
 */
trait InteractsWithWebsocket
{

    /**
     * @var RoomInterface
     */
    protected $wsRoom;

    protected $wsPusher = [];

    protected $wsEnable = false;

    /**
     * "onHandShake" listener.
     *
     * @param Request $req
     * @param Response $res
     */
    public function onHandShake($req, $res)
    {
        $this->runInSandbox(function (App $app, Websocket $websocket, HandlerInterface $handler) use ($req, $res) {
            $res->upgrade();

            $request = $this->prepareRequest($req);
            $request = $this->setRequestThroughMiddleware($app, $request);

            try {
                $fd = "{$this->workerId}.{$req->fd}";

                $this->wsPusher[$req->fd] = function ($message) use ($handler, $res) {
                    $res->push($handler->encodeMessage($message));
                };

                $websocket->setSender($fd);
                $handler->onOpen($request);

                while (true) {
                    $frame = $res->recv();
                    if ($frame === '' || $frame === false) {
                        break;
                    }

                    if ($frame->data == 'close' || get_class($frame) === CloseFrame::class) {
                        break;
                    }

                    $handler->onMessage($frame);
                }

                //关闭连接
                $res->close();

                $handler->onClose($req->fd);
            } finally {
                // leave all rooms
                $websocket->leave();
                unset($this->wsPusher[$req->fd]);
            }
        });
    }

    /**
     * @param App $app
     * @param \think\Request $request
     * @return \think\Request
     */
    protected function setRequestThroughMiddleware(App $app, \think\Request $request)
    {
        $app->instance('request', $request);
        return Middleware
            ::make($app, $this->getConfig('websocket.middleware', []))
            ->pipeline()
            ->send($request)
            ->then(function ($request) {
                return $request;
            });
    }

    /**
     * Prepare settings if websocket is enabled.
     */
    protected function prepareWebsocket()
    {
        $this->prepareWebsocketRoom();

        $this->onEvent('message', function ($message) {
            if ($message instanceof PushMessage) {
                if ($this->wsPusher[$message->fd]) {
                    $this->wsPusher[$message->fd]($message->data);
                }
            }
        });

        $this->onEvent('workerStart', function () {
            $this->bindWebsocketRoom();
            $this->bindWebsocketHandler();
            $this->prepareWebsocketListener();
        });
    }

    /**
     * Prepare websocket room.
     */
    protected function prepareWebsocketRoom()
    {
        $this->wsRoom = $this->container->make(Room::class);
        $this->wsRoom->prepare();
    }

    protected function prepareWebsocketListener()
    {
        $listeners = $this->getConfig('websocket.listen', []);

        foreach ($listeners as $event => $listener) {
            $this->app->event->listen('swoole.websocket.' . Str::studly($event), $listener);
        }

        $subscribers = $this->getConfig('websocket.subscribe', []);

        foreach ($subscribers as $subscriber) {
            $this->app->event->observe($subscriber, 'swoole.websocket.');
        }
    }

    /**
     * Prepare websocket handler for onOpen and onClose callback
     */
    protected function bindWebsocketHandler()
    {
        $handlerClass = $this->getConfig('websocket.handler');
        $this->app->bind(HandlerInterface::class, $handlerClass);
    }

    /**
     * Bind room instance to app container.
     */
    protected function bindWebsocketRoom(): void
    {
        $this->app->instance(Room::class, $this->wsRoom);
    }

}
