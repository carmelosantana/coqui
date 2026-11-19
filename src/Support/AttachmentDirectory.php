<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Where API attachments are written for a turn to read.
 *
 * MessageHandler materialises attachment bytes here, and AgentRunner allows
 * images from here (plus the workspace) through its LocalImageResolver.
 */
final class AttachmentDirectory
{
    public static function path(): string
    {
        return sys_get_temp_dir() . '/coqui-attachments';
    }
}
