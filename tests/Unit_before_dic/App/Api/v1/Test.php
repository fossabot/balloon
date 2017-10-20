<?php

declare(strict_types=1);

/**
 * Balloon
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2012-2017 gyselroth GmbH (https://gyselroth.com)
 * @license     GPL-3.0 https://opensource.org/licenses/GPL-3.0
 */

namespace Balloon\Testsuite\Unit\App\Api\v1;

use Balloon\App\Delta;
use Balloon\Testsuite\Unit\Test as UnitTest;

abstract class Test extends UnitTest
{
    public static function setupMockServer($context = 'cli')
    {
        $server = parent::setupMockServer($context);
        //$server->getHook()->registerHook(Delta\Hook::class);

        return $server;
    }
}