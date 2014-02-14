<?php
/**
 * This file is part of the ImagePalette package.
 *
 * (c) Brian Foxwell <brian@foxwell.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Bfoxwell\ImagePalette;
require_once('ColorUtil.php');

use Bfoxwell\ImagePalette\Exception\UnsupportedFileTypeException;
use Imagick;

/**
 * Class ImagePalette
 *
 * Gets the prominent colors in a given image. To get common color matching, all pixels are matched
 * against a white-listed color palette.
 *
 * @package bfoxwell\ImagePalette
 */
class ImagePalette implements \IteratorAggregate
{
    /**
     * File or Url
     * @var string
     */
    protected $file;
    
    /**
     * Loaded Image
     * @var object
     */
    protected $loadedImage;
    
    /**
     * Process every Nth pixel
     * @var int
     */
    protected $precision;

    /**
     * Width of image
     * @var integer
     */
    protected $width;

    /**
     * Height of image
     * @var integer
     */
    protected $height;

    /**
     * Number of colors to return
     * @var integer
     */
    protected $paletteLength;

    /**
     * Colors Whitelist
     * @var array
     */
    protected $whiteList = array(
        0x660000, 0x990000, 0xcc0000, 0xcc3333, 0xea4c88, 0x993399,
        0x663399, 0x333399, 0x0066cc, 0x0099cc, 0x66cccc, 0x77cc33,
        0x669900, 0x336600, 0x666600, 0x999900, 0xcccc33, 0xffff00,
        0xffcc33, 0xff9900, 0xff6600, 0xcc6633, 0x996633, 0x663300,
        0x000000, 0x999999, 0xcccccc, 0xffffff, 0xE7D8B1, 0xFDADC7,
        0x424153, 0xABBCDA, 0xF5DD01
    );
    
    /**
     * Colors hits, keys are colors from whiteList
     * @var array
     */
    protected $whiteListHits;
    
    /**
     * Library used
     * Supported are GD and Imagick
     * @var string
     */
    protected $lib;

    /**
     * Constructor
     * @param string $file
     * @param int $precision
     * @param int $paletteLength
     */
    public function __construct($file, $precision = 10, $paletteLength = 5, $overrideLib = null)
    {
        $this->file = $file;
        $this->precision = $precision;
        $this->paletteLength = $paletteLength;
        
        // use provided libname or auto-detect
        $this->lib = $overrideLib ? $overrideLib : $this->detectLib();
        
        // creates an array with our colors as keys
        $this->whiteListHits = array_fill_keys($this->whiteList, 0);
        
        // go!
        $this->process($this->lib);
        
        // sort color-keyed array by hits
        arsort($this->whiteListHits);
        
        // sort whiteList accordingly
        $this->whiteList = array_keys($this->whiteListHits);
    }


    /**
     * Autodetect and pick a graphical library to use for processing.
     * @param $lib
     * @return string
     */
    protected function detectLib()
    {
        try {
            if (extension_loaded('gd') && function_exists('gd_info')) {
                return 'GD';
                
            } else if(extension_loaded('imagick')) {
                return 'Imagick';
                
            } else if(extension_loaded('gmagick')) {
                return 'Gmagick';
                
            }

            throw new \Exception(
                "Try installing one of the following graphic libraries php5-gd, php5-imagick, php5-gmagick.
            ");

        } catch(\Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }
    
    /**
     * Select a graphical library and start generating the Image Palette
     * @param string $lib
     * @throws \Exception
     */
    protected function process($lib)
    {
        try {
            
            $this->{'setWorkingImage' . $lib} ();
            $this->{'setImagesize' . $lib} ();
            
            $this->readPixels();
            
        } catch(\Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }
    
    /**
     * Load and set the working image.
     * @param $image
     * @param string $image
     */
    protected function setWorkingImageGD()
    {
        $extension = pathinfo($this->file, PATHINFO_EXTENSION);
        try {

            switch (strtolower($extension)) {
                case "png":
                    $this->loadedImage = imagecreatefrompng($this->file);
                    break;
                    
                case "jpg":
                case "jpeg":
                    $this->loadedImage = imagecreatefromjpeg($this->file);
                    break;
                    
                case "gif":
                    $this->loadedImage = imagecreatefromgif($this->file);
                    break;
                    
                case "bmp":
                    $this->loadedImage = imagecreatefrombmp($this->file);
                    break;
                    
                default:
                    throw new UnsupportedFileTypeException("The file type .$extension is not supported.");
            }

        } catch (UnsupportedFileTypeException $e) {
            echo $e->getMessage() . "\n";
        }
    }
    
    /**
     * Load and set working image
     *
     * @todo needs work
     * @param $image
     * @param string $image
     * @return mixed
     */
    protected function setWorkingImageImagick()
    {

        $file = file_get_contents($this->file);
        $temp = tempnam("/tmp", uniqid("ImagePalette_",true));
        file_put_contents($temp, $file);

        $this->loadedImage = new Imagick($temp);
    }
    
    /**
     * Load and set working image
     *
     * @todo needs work
     * @param $image
     * @param string $image
     * @return mixed
     */
    protected function setWorkingImageGmagick()
    {
        throw new \Exception("Gmagick not supported");
        return null;
    }
    
    /**
     * Get and set size of the image using GD.
     */
    protected function setImageSizeGD()
    {
        list($this->width, $this->height) = getimagesize($this->file);
    }
    
    /**
     * Get and set size of image using ImageMagick.
     */
    protected function setImageSizeImagick()
    {
        $d = $this->loadedImage->getImageGeometry();
        $this->width  = $d['width'];
        $this->height = $d['height'];
    }
    
    /**
     * For each interesting pixel, add its closest color to the loaded colors array
     * 
     * @return mixed
     */
    protected function readPixels()
    {
        // Row
        for ($x = 0; $x < $this->width; $x += $this->precision) {
            // Column
            for ($y = 0; $y < $this->height; $y += $this->precision) {
                
                list($rgba, $r, $g, $b) = $this->getPixelColor($x, $y);
                
                // transparent pixels don't really have a color
                if (self::isTransparent($rgba))
                    continue 1;
                
                $this->whiteListHits[ $this->getClosestColor($r, $g, $b) ]++;
            }
        }
    }
    
    /**
     * Returns an array describing the color at x,y
     * At index 0 is the color as a whole int (may include alpha)
     * At index 1 is the color's red value
     * At index 2 is the color's green value
     * At index 3 is the color's blue value
     * 
     * @param  int $x
     * @param  int $y
     * @return array
     */
    protected function getPixelColor($x, $y)
    {
        return $this->{'getPixelColor' . $this->lib} ($x, $y);
    }
    
    /**
     * Using  to retrive color information about a specified pixel
     * 
     * @see  getPixelColor()
     * @param  int $x
     * @param  int $y
     * @return array
     */
    protected function getPixelColorGD($x, $y)
    {
        $color = imagecolorat($this->loadedImage, $x, $y);
        $rgb = imagecolorsforindex($this->loadedImage, $color);
        
        return array(
            $color,
            $rgb['red'],
            $rgb['green'],
            $rgb['blue']
        );
    }
    
    /**
     * Using  to retrive color information about a specified pixel
     * 
     * @see  getPixelColor()
     * @param  int $x
     * @param  int $y
     * @return array
     */
    protected function getPixelColorImagick($x, $y)
    {
        $rgb = $this->loadedImage->getImagePixelColor($x,$y)->getColor();
        
        return array(
            $this->rgbToColor($rgb['r'], $rgb['g'], $rgb['b']),
            $rgb['r'],
            $rgb['g'],
            $rgb['b']
        );
    }

    protected function getPixelColorGmagick($x, $y)
    {
        throw new \Exception("Gmagick not supported");
        return;
    }
    
    /**
     * Get closest matching color
     * 
     * @param $r
     * @param $g
     * @param $b
     * @return mixed
     */
    protected function getClosestColor($r, $g, $b)
    {
        
        $bestKey = 0;
        $bestDiff = PHP_INT_MAX;
        $whiteListLength = count($this->whiteList);
        
        for ( $i = 0 ; $i < $whiteListLength ; $i++ ) {
            
            // get whitelisted values
            list($wlr, $wlg, $wlb) = self::colorToRgb($this->whiteList[$i]);
            
            // calculate difference (don't sqrt)
            $diff = pow($r - $wlr, 2) + pow($g - $wlg, 2) + pow($b - $wlb, 2);
            
            // see if we got a new best
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestKey = $i;
            }
        }
        
        return $this->whiteList[$bestKey];
    }
    
    /**
     * Returns the color palette as an array containing
     * an integer for each color
     * 
     * @param  int $paletteLength
     * @return int
     */
    public function getIntColors($paletteLength = null)
    {
        // allow custom length calls
        if (!is_numeric($paletteLength)) {
            $paletteLength = $this->paletteLength;
        }
        
        // take the best hits
        return array_slice($this->whiteList, 0, $paletteLength, true);
    }
    
    /**
     * Returns the color palette as an array containing
     * each color as an array of red, green and blue values
     * 
     * @param  int $paletteLength
     * @return array
     */
    public function getRgbColors($paletteLength = null)
    {
        return array_map(
            'Bfoxwell\ImagePalette\ColorUtil::intToRgb',
            $this->getIntColors($paletteLength)
        );
    }
    
    /**
     * Returns the color palette as an array containing
     * hexadecimal string representations, like '#abcdef'
     * 
     * @param  int $paletteLength
     * @return array
     */
    public function getHexStringColors($paletteLength = null)
    {
        return array_map(
            'Bfoxwell\ImagePalette\ColorUtil::intToHexString',
            $this->getIntColors($paletteLength)
        );
    }
    
    /**
     * Returns the color palette as an array containing
     * decimal string representations, like 'rgb(123,0,20)'
     * 
     * @param  int $paletteLength
     * @return array
     */
    public function getRgbStringColors($paletteLength = null)
    {
        return array_map(
            'Bfoxwell\ImagePalette\ColorUtil::rgbToString',
            $this->getRgbColors($paletteLength)
        );
    }
    
    /**
     * Alias for getHexStringColors for legacy support.
     * 
     * @deprecated  use one of the newer getters
     * @param  int $paletteLength
     * @return array
     */
    public function getColors($paletteLength = null)
    {
        return $this->getHexStringColors($paletteLength);
    }
    
    /**
     * Returns a json encoded version of the palette
     * 
     * @return string
     */
    public function __toString()
    {
        return json_encode($this->getHexStringColors());
    }
    
    /**
     * Convenient getter access as properties
     * 
     * @return  mixed
     */
    public function __get($name)
    {
        $method = 'get' . ucfirst($name);
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        throw new \Exception("Method $method does not exist");
    }
    
    /**
     * Returns the palette for implementation of the IteratorAggregate interface
     * Used in foreach loops
     * 
     * @see  getColors()
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->getColors());
    }
}
