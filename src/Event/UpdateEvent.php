<?php

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Event;

use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class UpdateEvent extends Event
{
    public function __construct(private readonly bool $force, private readonly bool $reset, private readonly bool $assumeYesForPrompts)
    {
        $this->name = ScriptEvents::POST_UPDATE_CMD;
    }

    public function force(): bool
    {
        return $this->force;
    }

    public function reset(): bool
    {
        return $this->reset;
    }

    public function assumeYesForPrompts(): bool
    {
        return $this->assumeYesForPrompts;
    }
}
