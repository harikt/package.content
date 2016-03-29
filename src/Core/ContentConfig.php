<?php declare(strict_types = 1);

namespace Dms\Package\Content\Core;

/**
 * The content configuration class.
 *
 * @author Elliot Levin <elliotlevin@hotmail.com>
 */
class ContentConfig
{
    /**
     * @var string
     */
    protected $imageStorageBasePath;

    /**
     * @var string
     */
    protected $imageBaseUrl;

    /**
     * ContentConfig constructor.
     *
     * @param string $imageStorageBasePath
     * @param string $imageBaseUrl
     */
    public function __construct(string $imageStorageBasePath, string $imageBaseUrl)
    {
        $this->imageStorageBasePath = $imageStorageBasePath;
        $this->imageBaseUrl         = $imageBaseUrl;
    }

    /**
     * @return string
     */
    public function getImageStorageBasePath() : string
    {
        return $this->imageStorageBasePath;
    }

    /**
     * @return string
     */
    public function getImageBaseUrl() : string
    {
        return $this->imageBaseUrl;
    }
}