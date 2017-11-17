<?php

declare(strict_types=1);

/**
 * Balloon
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2012-2017 gyselroth GmbH (https://gyselroth.com)
 * @license     GPL-3.0 https://opensource.org/licenses/GPL-3.0
 */

namespace Balloon\App\Webdav\App;

use Balloon\App\AppInterface;
use Balloon\Server;
use Micro\Http\Router;
use Micro\Http\Router\Route;
use Sabre\DAV;

class Http implements AppInterface
{
    /**
     * Server.
     *
     * @var Server
     */
    protected $server;

    /**
     * Constructor.
     *
     * @param Router $router
     * @param Server $server
     */
    public function __construct(Router $router, Server $server)
    {
        $this->server = $server;
        $router->appendRoute(new Route('/webdav', $this, 'start'));
    }

    /**
     * Start.
     *
     * @return bool
     */
    public function start(): bool
    {
        $root = $this->server->getFilesystem()->getRoot();

        $server = new DAV\Server($root);
        $server->setBaseUri('/webdav/');

        $lockBackend = new DAV\Locks\Backend\File('/tmp/locks');
        $lockPlugin = new DAV\Locks\Plugin($lockBackend);
        $server->addPlugin($lockPlugin);

        $plugin = new DAV\Browser\Plugin();
        $server->addPlugin($plugin);

        $authBackend = new DAV\Auth\Backend\Apache();
        $authPlugin = new DAV\Auth\Plugin($authBackend, 'SabreDAV');
        $server->addPlugin($authPlugin);

        $server->exec();

        return true;
    }
}