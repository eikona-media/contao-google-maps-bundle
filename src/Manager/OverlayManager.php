<?php

/*
 * Copyright (c) 2023 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\GoogleMapsBundle\Manager;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\System;
use HeimrichHannot\GoogleMapsBundle\DataContainer\Overlay;
use HeimrichHannot\GoogleMapsBundle\Model\OverlayModel;
use HeimrichHannot\TwigSupportBundle\Renderer\TwigTemplateRenderer;
use HeimrichHannot\UtilsBundle\File\FileUtil;
use HeimrichHannot\UtilsBundle\Location\LocationUtil;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;
use Ivory\GoogleMap\Base\Coordinate;
use Ivory\GoogleMap\Base\Point;
use Ivory\GoogleMap\Base\Size;
use Ivory\GoogleMap\Event\Event;
use Ivory\GoogleMap\Event\MouseEvent;
use Ivory\GoogleMap\Layer\KmlLayer;
use Ivory\GoogleMap\Map;
use Ivory\GoogleMap\Overlay\Icon;
use Ivory\GoogleMap\Overlay\InfoWindow;
use Ivory\GoogleMap\Overlay\Marker;
use Ivory\GoogleMap\Overlay\Polygon;

class OverlayManager
{
    const CACHE_KEY_PREFIX = 'googleMaps_overlay';
    /**
     * @var ContaoFramework
     */
    protected $framework;

    /**
     * @var ModelUtil
     */
    protected $modelUtil;

    /**
     * @var LocationUtil
     */
    protected $locationUtil;

    /**
     * @var array
     */
    protected static $markerVariableMapping = [];
    /**
     * @var TwigTemplateRenderer
     */
    private $templateRenderer;
    /**
     * @var FileUtil
     */
    private $fileUtil;

    public function __construct(
        ContaoFramework $framework,
        ModelUtil $modelUtil,
        LocationUtil $locationUtil,
        FileUtil $fileUtil,
        TwigTemplateRenderer $templateRenderer
    ) {
        $this->framework = $framework;
        $this->modelUtil = $modelUtil;
        $this->locationUtil = $locationUtil;
        $this->templateRenderer = $templateRenderer;
        $this->fileUtil = $fileUtil;
    }

    public function addOverlayToMap(Map $map, OverlayModel $overlayConfig, string $apiKey): void
    {
        $this->apiKey = $apiKey;

        switch ($overlayConfig->type) {
            case Overlay::TYPE_MARKER:
                [$marker, $events] = $this->prepareMarker($overlayConfig, $map);

                $map->getOverlayManager()->addMarker($marker);

                foreach ($events as $event) {
                    $map->getEventManager()->addDomEvent($event);
                }

                break;

            case Overlay::TYPE_INFO_WINDOW:
                $infoWindow = $this->prepareInfoWindow($overlayConfig);
                $infoWindow->setOpen(true);

                $map->getOverlayManager()->addInfoWindow($infoWindow);

                break;

            case Overlay::TYPE_KML_LAYER:
                $kmlLayer = $this->prepareKmlLayer($overlayConfig);

                $map->getLayerManager()->addKmlLayer($kmlLayer);

                break;

            case Overlay::TYPE_POLYGON:
                $polygon = $this->preparePolygon($overlayConfig);

                $map->getOverlayManager()->addPolygon($polygon);

                break;

            default:
                // TODO allow event subscribers
                break;
        }
    }

    public function addRoutingToInfoWindow(InfoWindow $infoWindow, OverlayModel $overlayConfig)
    {
        $position = $infoWindow->getPosition();

        if ($overlayConfig->addRouting && $position) {
            $template = $overlayConfig->routingTemplate ?: 'gmap_routing_default';
            $routing = $this->templateRenderer->render($template, [
                'lat' => $position->getLatitude(),
                'lng' => $position->getLongitude(),
            ]);
            $infoWindow->setContent($infoWindow->getContent().$routing);
        }
    }

    /**
     * @param Marker|InfoWindow $overlay
     *
     * @throws \Exception
     */
    public function setPositioning($overlay, OverlayModel $overlayConfig)
    {
        switch ($overlayConfig->positioningMode) {
            case Overlay::POSITIONING_MODE_COORDINATE:
                $overlay->setPosition(new Coordinate($overlayConfig->positioningLat, $overlayConfig->positioningLng));

                break;

            case Overlay::POSITIONING_MODE_STATIC_ADDRESS:
                if (!($coordinates = System::getContainer()->get('huh.utils.cache.database')->getValue(static::CACHE_KEY_PREFIX.$overlayConfig->positioningAddress))) {
                    $coordinates = $this->locationUtil->computeCoordinatesByString($overlayConfig->positioningAddress, $this->apiKey);

                    if (\is_array($coordinates)) {
                        $coordinates = serialize($coordinates);
                        System::getContainer()->get('huh.utils.cache.database')->cacheValue(static::CACHE_KEY_PREFIX.$overlayConfig->positioningAddress, $coordinates);
                    }
                }

                if (\is_string($coordinates)) {
                    $coordinates = StringUtil::deserialize($coordinates, true);

                    if (isset($coordinates['lat']) && isset($coordinates['lng'])) {
                        $overlay->setPosition(new Coordinate($coordinates['lat'], $coordinates['lng']));
                    }
                }

                break;
        }
    }

    public static function getMarkerVariableMapping(): array
    {
        return static::$markerVariableMapping;
    }

    public static function setMarkerVariableMapping(array $markerVariableMapping): void
    {
        static::$markerVariableMapping = $markerVariableMapping;
    }

    public static function checkHex(string $hex): string
    {
        if ('' == trim($hex, '0..9A..Fa..f')) {
            return '#'.$hex;
        }

        return '#000000';
    }

    protected function prepareMarker(OverlayModel $overlayConfig, Map $map)
    {
        $events = [];
        $marker = new Marker(new Coordinate());
        $this->setPositioning($marker, $overlayConfig);

        static::$markerVariableMapping[$overlayConfig->id] = $marker->getVariable();

        switch ($overlayConfig->markerType) {
            case Overlay::MARKER_TYPE_SIMPLE:
                break;

            case Overlay::MARKER_TYPE_ICON:
                $icon = new Icon();

                // image file
                $filePath = $this->fileUtil->getPathFromUuid($overlayConfig->iconSrc);

                if ($filePath) {
                    $icon->setUrl($filePath);
                }

                // anchor
                $icon->setAnchor(new Point($overlayConfig->iconAnchorX ?? 0, $overlayConfig->iconAnchorY ?? 0));

                // size
                $width = StringUtil::deserialize($overlayConfig->iconWidth, true);
                $height = StringUtil::deserialize($overlayConfig->iconHeight, true);

                if ($width['value'] && $height['value']) {
                    $icon->setScaledSize(new Size($width['value'], $height['value'], $width['unit'], $height['unit']));
                } else {
                    throw new \Exception('The overlay ID '.$overlayConfig->id.' doesn\'t have a icon width and height set.');
                }

                $marker->setIcon($icon);

                break;
        }

        if ($overlayConfig->animation) {
            $marker->setAnimation($overlayConfig->animation);
        }

        if ($overlayConfig->zIndex) {
            $marker->setOption('zIndex', (int) $overlayConfig->zIndex);
        }

        // title
        switch ($overlayConfig->titleMode) {
            case Overlay::TITLE_MODE_TITLE_FIELD:
                $marker->setOption('title', $overlayConfig->title);

                break;

            case Overlay::TITLE_MODE_CUSTOM_TEXT:
                $marker->setOption('title', $overlayConfig->titleText);

                break;
        }

        // events
        if ($overlayConfig->clickEvent) {
            $marker->addOptions(['clickable' => true]);

            switch ($overlayConfig->clickEvent) {
                case Overlay::CLICK_EVENT_LINK:
                    /** @var Controller $controller */
                    $controller = $this->framework->getAdapter(Controller::class);
                    $url = $controller->replaceInsertTags($overlayConfig->url);

                    $event = new Event(
                        $marker->getVariable(),
                        'click',
                        "function() {
                            var win = window.open('".$url."', '".($overlayConfig->target ? '_blank' : '_self')."');
                        }"
                    );

                    $events[] = $event;

                    break;

                case Overlay::CLICK_EVENT_INFO_WINDOW:
                    $infoWindow = $this->prepareInfoWindow($overlayConfig);
                    $infoWindow->setPixelOffset(new Size(($overlayConfig->infoWindowAnchorX ?? 0), $overlayConfig->infoWindowAnchorY ?? 0));
                    $infoWindow->setOpenEvent(MouseEvent::CLICK);
                    // caution: this autoOpen is different from the one in dlh google maps
                    $infoWindow->setAutoOpen(true);

                    $marker->setInfoWindow($infoWindow);

                    break;
            }
        }

        return [$marker, $events];
    }

    protected function prepareInfoWindow(OverlayModel $overlayConfig)
    {
        $infoWindow = new InfoWindow($overlayConfig->infoWindowText);
        $this->setPositioning($infoWindow, $overlayConfig);
        $this->addRoutingToInfoWindow($infoWindow, $overlayConfig);

        // size
        $width = StringUtil::deserialize($overlayConfig->infoWindowWidth, true);
        $height = StringUtil::deserialize($overlayConfig->infoWindowHeight, true);
        $sizing = [];

        if (isset($width['value']) && $width['value']) {
            $sizing[] = 'width: '.$width['value'].$width['unit'].';';
        }

        if (isset($height['value']) && $height['value']) {
            $sizing[] = 'height: '.$height['value'].$height['unit'].';';
        }

        if (!empty($sizing)) {
            $infoWindow->setContent(
                '<div class="wrapper" style="'.implode(' ', $sizing).'">'.$infoWindow->getContent().'</div>'
            );
        }

        if ($overlayConfig->zIndex) {
            $infoWindow->setOption('zIndex', (int) $overlayConfig->zIndex);
        }

        return $infoWindow;
    }

    protected function prepareKmlLayer(OverlayModel $overlayConfig)
    {
        $kmlLayer = new KmlLayer($overlayConfig->kmlUrl);

        if ($overlayConfig->kmlClickable) {
            $kmlLayer->setOption('clickable', (bool) $overlayConfig->kmlClickable);
        }

        if ($overlayConfig->kmlPreserveViewport) {
            $kmlLayer->setOption('preserveViewport', (bool) $overlayConfig->kmlPreserveViewport);
        }

        if ($overlayConfig->kmlScreenOverlays) {
            $kmlLayer->setOption('screenOverlays', (bool) $overlayConfig->kmlScreenOverlays);
        }

        if ($overlayConfig->kmlSuppressInfowindows) {
            $kmlLayer->setOption('suppressInfoWindows', (bool) $overlayConfig->kmlSuppressInfowindows);
        }

        if ($overlayConfig->zIndex) {
            $kmlLayer->setOption('zIndex', (int) $overlayConfig->zIndex);
        }

        return $kmlLayer;
    }

    protected function preparePolygon(OverlayModel $overlayConfig)
    {
        $polygon = new Polygon();

        // position settings
        $vertices = StringUtil::deserialize($overlayConfig->pathCoordinates, true);
        $verticesArray = [];

        foreach ($vertices as $vertex) {
            $verticesArray[] = new Coordinate($vertex['positioningLat'] ?? 0, $vertex['positioningLng'] ?? 0);
        }

        $polygon->setCoordinates($verticesArray);

        // stroke settings
        if ($overlayConfig->strokeWeight) {
            $polygon->setOption('strokeWeight', (int) $overlayConfig->strokeWeight);
        }

        if ($overlayConfig->strokeColor) {
            $polygon->setOption('strokeColor', self::checkHex($overlayConfig->strokeColor));
        }

        if ($overlayConfig->strokeOpacity) {
            $polygon->setOption('strokeOpacity', (float) $overlayConfig->strokeOpacity);
        }

        // fill settings
        if ($overlayConfig->fillColor) {
            $polygon->setOption('fillColor', self::checkHex($overlayConfig->fillColor));
        }

        if ($overlayConfig->fillOpacity) {
            $polygon->setOption('fillOpacity', (float) $overlayConfig->fillOpacity);
        }

        // other settings
        if ($overlayConfig->zIndex) {
            $polygon->setOption('zIndex', (int) $overlayConfig->zIndex);
        }

        return $polygon;
    }
}
