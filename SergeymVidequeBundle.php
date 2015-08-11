<?php

namespace Sergeym\VidequeBundle;

use Sergeym\VidequeBundle\DependencyInjection\VidequeExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SergeymVidequeBundle extends Bundle
{
    //override function to allow "php_ffmpeg" alias
    public function getContainerExtension()
    {
        if (null === $this->extension) {
            $this->extension = new VidequeExtension();
        }

        return $this->extension;
    }
}
