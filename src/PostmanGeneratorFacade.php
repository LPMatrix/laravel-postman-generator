<?php

namespace LPMatrix\PostmanGenerator;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LPMatrix\PostmanGenerator\Skeleton\SkeletonClass
 */
class PostmanGeneratorFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'postman-generator';
    }
}
