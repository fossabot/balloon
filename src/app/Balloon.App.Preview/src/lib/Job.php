<?php
declare(strict_types=1);

/**
 * Balloon
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2012-2017 gyselroth GmbH (https://gyselroth.com)
 * @license     GPLv3 https://opensource.org/licenses/GPL-3.0
 */

namespace Balloon\App\Preview;

use \Psr\Log\LoggerInterface as Logger;
use \Balloon\Server;
use \Balloon\Async\AbstractJob;

class Job extends AbstractJob
{
    /**
     * Start job
     *
     * @param  Server $server
     * @param  Logger $logger
     * @return bool
     */
    public function start(Server $server, Logger $logger): bool
    {
        $file = $server->getFilesystem()->findNodeWithId($this->data['id']);

        $logger->info("create preview for node [".$this->data['id']."]", [
            'category' => get_class($this),
        ]);
        
        $result = $server->getApp()
            ->getApp('Balloon.App.Preview')
            ->getConverter()
            ->convert($file, 'png');

        $file->setPreview($result->getContents());
        return true;
    }
}
