<?php
namespace Sergeym\VidequeBundle\Provider;

use Mmoreram\RSQueueBundle\Services\Producer;
use Sonata\CoreBundle\Model\Metadata;
use Sonata\MediaBundle\Provider\BaseProvider;
use Sonata\MediaBundle\Entity\BaseMedia as Media;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Provider\BaseVideoProvider;
use Sonata\MediaBundle\Provider\FileProvider;
use Sonata\MediaBundle\Resizer\ResizerInterface;

use Gaufrette\Adapter\Local;
use Sonata\MediaBundle\CDN\CDNInterface;
use Sonata\MediaBundle\Generator\GeneratorInterface;
use Sonata\MediaBundle\Thumbnail\ThumbnailInterface;
use Sonata\MediaBundle\Metadata\MetadataBuilderInterface;

use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Validator\ErrorElement;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Form\FormBuilder;


use Gaufrette\Filesystem;
use FFMpeg\FFProbe;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;

use GetId3\GetId3Core as GetId3;

use Symfony\Component\Form\Form;

class VidequeProvider extends FileProvider
{
    const QUEUE_NAME = 'VidequeProvider';
    const FIELD_NAME_SCREENSHOT_AT = 'screenshotAt';
    const DEFAULT_THUMB_FORMAT = 'jpg';

    protected $allowedExtensions;

    protected $allowedMimeTypes;

    protected $metadata;

    protected $ffprobe;

    protected $ffmpeg;

    protected $producer;

    /**
     * @param string $name
     * @param Filesystem $filesystem
     * @param CDNInterface $cdn
     * @param GeneratorInterface $pathGenerator
     * @param ThumbnailInterface $thumbnail
     * @param array $allowedExtensions
     * @param array $allowedMimeTypes
     * @param ResizerInterface $resizer
     * @param MetadataBuilderInterface|null $metadata
     */
    public function __construct($name, Filesystem $filesystem, CDNInterface $cdn, GeneratorInterface $pathGenerator, ThumbnailInterface $thumbnail, array $allowedExtensions = array(), array $allowedMimeTypes = array(), ResizerInterface $resizer, MetadataBuilderInterface $metadata = null, Producer $producer)
    {
        parent::__construct($name, $filesystem, $cdn, $pathGenerator, $thumbnail, $allowedExtensions, $allowedMimeTypes, $metadata);

        $this->resizer = $resizer;
        $this->producer = $producer;
    }

    public function generateFilePath($media, $format)
    {
        return $this->thumbnail->generatePrivateUrl($this, $media, $format);
    }

    protected function doTransform(MediaInterface $media)
    {
        parent::doTransform($media);
        $media->setProviderStatus(MediaInterface::STATUS_ENCODING);
    }


    public function generateThumbnails(MediaInterface $media)
    {
        $this->producer->produce(self::QUEUE_NAME, [
            'id' => $media->getId()
        ]);
    }


    public function buildCreateForm(FormMapper $formMapper)
    {
        $formMapper->add('binaryContent', 'file');
        $formMapper->add('providerMetadata', 'sonata_type_immutable_array', [
            'keys' => [
                [self::FIELD_NAME_SCREENSHOT_AT, 'integer', ['data' => 10]],
            ]
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function generatePublicUrl(MediaInterface $media, $format)
    {
        if ($format == 'reference') {
            $path = $this->getReferenceImage($media);
        } else {
            $path = $this->thumbnail->generatePublicUrl($this, $media, $format);
        }

        return $this->getCdn()->getPath($path, $media->getCdnIsFlushable());
    }

    public function generatePublicThumbUrl(MediaInterface $media, $format)
    {
        $extension = $media->getExtension();

        if (!isset($params['format']) || !$params['format']) {
            $params['format'] = self::DEFAULT_THUMB_FORMAT;
        }

        return preg_replace('/'.$extension.'$/i', $params['format'], $this->generatePublicUrl($media, $format));
    }


    public function generatePrivateThumbUrl(Media $media, $format) {
        $params = $this->getFormat($format);
        $extension = $media->getExtension();
        if (!isset($params['format']) || !$params['format']) {
            $params['format'] = self::DEFAULT_THUMB_FORMAT;
        }
        $privUrl = $this->getReferenceImage($media);
        return preg_replace('/'.$extension.'$/i', $params['format'], $privUrl);
    }

    public function generateThumbPath(Media $media, $format) {
        return sprintf('%s/%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePrivateThumbUrl($media, $format)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getHelperProperties(MediaInterface $media, $format='reference', $options = array())
    {
        $sources = [
            [
                'file' => $this->generatePublicUrl($media, 'reference'),
                'label' => 'Original'
            ]
        ];

        $formats = $this->getFormats();
        foreach($formats as $format => $params) {
            if ($format !== 'admin') {
                $sources[] = [
                    'file' => $this->generatePublicUrl($media, $format),
                    'label' => $format,
                ];
            }
        }

        return array_merge(array(
            'title'       => $media->getName(),
            'thumbnail'   => $this->generatePublicThumbUrl($media, 'reference'),
            'sources'     => $sources
        ), $options);
    }

    public function getReferenceFilePath(Media $media)
    {
        return sprintf('%s/%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->getReferenceImage($media)
        );
    }



}
