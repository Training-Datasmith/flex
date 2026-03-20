<?php

declare (strict_types=1);
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
use Composer\Script\Script_Events;
class Update_Event extends Event
{
    public function __construct(private readonly bool $force, private readonly bool $reset, private readonly bool $assume_yes_for_prompts)
    {
        $this->name = Script_Events::POST_UPDATE_CMD;
    }
    public function force(): bool
    {
        return $this->force;
    }
    public function reset(): bool
    {
        return $this->reset;
    }
    public function assume_yes_for_prompts(): bool
    {
        return $this->assume_yes_for_prompts;
    }
}