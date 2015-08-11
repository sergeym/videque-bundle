<?php

namespace Sergeym\VidequeBundle\Format\Video;
use FFMpeg\Format\Video\DefaultVideo;
use FFMpeg\Format\Video\X264;

/**
 * The X264 single pass video format
 */
class X264SinglePass extends X264
{
    /**
     * {@inheritDoc}
     */
    public function getPasses()
    {
        return 1;
    }
}
