<?php

//phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh
//phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged

namespace omz13;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Exception\LogicException;

use const DATE_ATOM;
use const XMLSITEMAP_CONFIGURATION_PREFIX;
use const XMLSITEMAP_VERSION;

use function array_key_exists;
use function array_push;
use function assert;
use function date;
use function define;
use function file_exists;
use function file_get_contents;
use function filemtime;
use function in_array;
use function is_array;
use function json_encode;
use function kirby;
use function md5;
use function microtime;
use function strtotime;
use function time;

define( 'XMLSITEMAP_VERSION', '0.5.0' );
define( 'XMLSITEMAP_CONFIGURATION_PREFIX', 'omz13.xmlsitemap' );

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class XMLSitemap
{
  private static $debug;
  private static $optionCACHE; // cache TTL in *minutes*; if zero or null, no cache
  private static $optionNOIMG; // disable including image data
  private static $optionIUWSI; // include unlisted when slug is
  private static $optionXCWTI; // exclude children when template is
  private static $optionXPWTI; // exclude page when template is
  private static $optionXPWSI; // exclude page when slug is
  private static $optionShimH;
  public static $version = XMLSITEMAP_VERSION;

  public static function ping() : string {
    return static::class . ' pong ' . static::$version;
  }//end ping()

  public static function isEnabled() : bool {
    if ( self::getConfigurationForKey( 'disable' ) == 'true' ) {
      return false;
    }

    if ( kirby()->site()->content()->get( 'xmlsitemap' ) == 'false' ) {
      return false;
    }

    return true;
  }//end isEnabled()

  public static function getArrayConfigurationForKey( string $key ) : ?array {
    // Try to pick up configuration when provided in an array (vendor.plugin.array(key=>value))
    $o = kirby()->option( XMLSITEMAP_CONFIGURATION_PREFIX );
    if ( $o != null && is_array( $o ) && array_key_exists( $key, $o ) ) {
      return $o[$key];
    }

    // try to pick up configuration as a discrete (vendor.plugin.key=>value)
    $o = kirby()->option( XMLSITEMAP_CONFIGURATION_PREFIX . '.' . $key );
    if ( $o != null ) {
      return $o;
    }

    // this should not be reached... because plugin should define defaults for all its options...
    return null;
  }//end getArrayConfigurationForKey()

  public static function getConfigurationForKey( string $key ) : string {
    // Try to pick up configuration when provided in an array (vendor.plugin.array(key=>value))
    $o = kirby()->option( XMLSITEMAP_CONFIGURATION_PREFIX );
    if ( $o != null && is_array( $o ) && array_key_exists( $key, $o ) ) {
      return $o[$key];
    }

    // try to pick up configuration as a discrete (vendor.plugin.key=>value)
    $o = kirby()->option( XMLSITEMAP_CONFIGURATION_PREFIX . '.' . $key );
    if ( $o != null ) {
      return $o;
    }

    // this should not be reached... because plugin should define defaults for all its options...
    return "";
  }//end getConfigurationForKey()

  public static function getStylesheet() : string {
    $f = null;
    if ( static::getConfigurationForKey( 'x-shimAssets' ) == true ) {
      $f = @file_get_contents( kirby()->root( 'assets' ) . '/xmlsitemap/xmlsitemap.xsl' );
    }
    if ( $f == null ) {
      $f = file_get_contents( __DIR__ . '/../assets/xmlsitemap.xsl' );
      if ( $f == null ) {
        throw new LogicException( 'Failed to read embedded sitemap.xsl' );
      }
    }
    return $f;
  }//end getStylesheet()

  private static function pickupOptions() : void {
    static::$optionCACHE = static::getConfigurationForKey( 'cacheTTL' );
    static::$optionNOIMG = static::getConfigurationForKey( 'disableImages' );
    static::$optionIUWSI = static::getArrayConfigurationForKey( 'includeUnlistedWhenSlugIs' );
    static::$optionXCWTI = static::getArrayConfigurationForKey( 'excludeChildrenWhenTemplateIs' );
    static::$optionXPWTI = static::getArrayConfigurationForKey( 'excludePageWhenTemplateIs' );
    static::$optionXPWSI = static::getArrayConfigurationForKey( 'excludePageWhenSlugIs' );
    static::$optionShimH = static::getConfigurationForKey( 'x-shimHomepage' );
  }//end pickupOptions()

  /**
   * @SuppressWarnings("Complexity")
   */
  public static function getSitemap( Pages $p, bool $debug = false ) : string {
    static::$debug = $debug && kirby()->option( 'debug' ) !== null && kirby()->option( 'debug' ) == true;
    static::pickupOptions();

    $tbeg = microtime( true );

    // if cacheTTL disabled...
    if ( static::$optionCACHE == null || static::$optionCACHE == "" ) {
      $r = static::generateSitemap( $p, $debug );
      if ( static::$debug == true ) {
        $r .= "<!-- Freshly generated; not cached for reuse -->\n";
      }
    } else {
      // try to read from cache; generate if expired
      $cacheCache = kirby()->cache( XMLSITEMAP_CONFIGURATION_PREFIX );

      // build list of options
      $ops  = json_encode( static::$optionCACHE );
      $ops .= '-' . json_encode( static::$optionNOIMG );
      $ops .= '-' . json_encode( static::$optionIUWSI );
      $ops .= '-' . json_encode( static::$optionXCWTI );
      $ops .= '-' . json_encode( static::$optionXPWSI );
      $ops .= '-' . json_encode( static::$optionXPWTI );
      $ops .= '-' . json_encode( static::$optionShimH );
      $ops .= '-' . json_encode( $debug );

      $cacheName = XMLSITEMAP_VERSION . '-sitemap-' . md5( $ops );

      $r = $cacheCache->get( $cacheName );
      if ( $r == null ) {
        $r = static::generateSitemap( $p, $debug );
        $cacheCache->set( $cacheName, $r, static::$optionCACHE );
        if ( static::$debug == true ) {
          $r .= '<!-- Freshly generated; cached into ' . md5( $ops ) . ' for ' . static::$optionCACHE . " minute(s) for reuse -->\n";
        }
      } else {
        if ( static::$debug == true ) {
          $expiresAt       = $cacheCache->expires( $cacheName );
          $secondsToExpire = ( $expiresAt - time() );

          $r .= '<!-- Retrieved as ' . md5( $ops ) . ' from cache ; expires in ' . $secondsToExpire . " seconds -->\n";
        }
      }
    }//end if

    $tend = microtime( true );
    if ( static::$debug == true ) {
      $elapsed = ( $tend - $tbeg );
      $r      .= '<!-- That all took ' . ( 1000 * $elapsed ) . " microseconds -->\n";
    }

    return $r;
  }//end getSitemap()

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
   */
  private static function generateSitemap( Pages $p, bool $debug = false ) : string {
    static::pickupOptions();
    $tbeg = microtime( true );

    $r  = '';
    $r .= "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $r .= "<?xml-stylesheet type=\"text/xsl\" href=\"/sitemap.xsl\"?>\n";
    $r .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';

    if ( static::$optionNOIMG != true ) {
      $r .= 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
    }

    $r .= ">\n";

    if ( $debug == true ) {
      $r .= '<!--                 disableImages = ' . json_encode( static::$optionNOIMG ) . " -->\n";
      $r .= '<!--     includeUnlistedWhenSlugIs = ' . json_encode( static::$optionIUWSI ) . " -->\n";
      $r .= '<!-- excludeChildrenWhenTemplateIs = ' . json_encode( static::$optionXCWTI ) . " -->\n";
      $r .= '<!--     excludePageWhenTemplateIs = ' . json_encode( static::$optionXPWTI ) . " -->\n";
      $r .= '<!--         excludePageWhenSlugIs = ' . json_encode( static::$optionXPWSI ) . " -->\n";
      $r .= '<!--                x-shimHomepage = ' . json_encode( static::$optionShimH ) . " -->\n";
    }

    if ( kirby()->multilang() == true ) {
      $langs = [];

      static::addComment( $r, 'Processing as ML; number of languages = ' . kirby()->languages()->count() );
      assert( kirby()->languages()->count() > 0 );
      foreach ( kirby()->languages() as $lang ) {
        array_push( $langs, $lang->code() );
      }

      static::addComment( $r, 'ML languages are ' . json_encode( $langs ) );
      static::addComment( $r, 'ML default is "' . json_encode( kirby()->language()->code() ) );

      if ( static::$optionShimH == true ) {
        // add explicit entry for homepage to point to l10n homepages
        static::addComment( $r, 'ML confabulating a HOMEPAGE' );

        $homepage = kirby()->site()->homePage();

        $r .= '<url>' . "\n";
        $r .= '  <loc>' . kirby()->url( 'index' ) . '</loc>' . "\n";
        $r .= '  <xhtml:link rel="alternate" hreflang="x-default" href="' . $homepage->urlForLanguage( kirby()->language()->code() ) . '" />' . "\n";
        foreach ( $langs as $lang ) {
          $r .= '  <xhtml:link rel="alternate" hreflang="' . $lang . '" href="' . $homepage->urlForLanguage( $lang ) . '" />' . "\n";
        }
        $r .= '</url>' . "\n";
      }

      // Add sitemap for each language
      foreach ( $langs as $lang ) {
        static::addComment( $r, 'ML for ' . $lang );
        if ( $lang == kirby()->language()->code() ) {
          static::addComment( $r, 'ML ' . $lang . ' is primary' );
          static::addPagesToSitemap( $p, $r, "--" );
        } else {
          static::addComment( $r, 'ML ' . $lang . ' is secondary' );
          static::addPagesToSitemap( $p, $r, $lang );
        }
      }
    } else {
      static::addComment( $r, 'Processing as SL' );
      static::addPagesToSitemap( $p, $r, null );
    }//end if

    $r .= "</urlset>\n";
    $r .= "<!-- Sitemap generated using https://github.com/omz13/kirby3-xmlsitemap -->\n";

    $tend = microtime( true );
    if ( $debug == true ) {
      $elapsed = ( $tend - $tbeg );

      $r .= '<!-- v' . static::$version . " -->\n";
      $r .= '<!-- Generation took ' . ( 1000 * $elapsed ) . " microseconds -->\n";
      $r .= '<!-- Generated at ' . date( DATE_ATOM, (int) $tend ) . " -->\n";
    }

    return $r;
  }//end generateSitemap()

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
  * @SuppressWarnings(PHPMD.NPathComplexity)
  * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
   */
  private static function addPagesToSitemap( Pages $pages, string &$r, ?string $langcode = null ) : void {
    $sortedpages = $pages->sortBy( 'url', 'asc' );
    foreach ( $sortedpages as $p ) {
      static::addComment( $r, 'crunching ' . $p->parent() . '/' . $p->uid() . ' [it=' . $p->intendedTemplate() . '] [s=' . $p->status() . '] [d=' . $p->depth() . ']' . ( $p->isHomePage() ? " HOMEPAGE" : "" ) );

      if ( $langcode == null ) {
        static::addComment( $r, '(  ) "' . $p->title() . '"' );
      } else {
        if ( $langcode == '--' ) {
          static::addComment( $r, '(--) "' . $p->title() . '"' );
        } else {
          // Guard just-in-case the translation data is corrupted and do fallback
          if ( array_key_exists( "title", $p->translationData( $langcode ) ) == true ) {
            static::addComment( $r, '(' . $langcode . ') "' . $p->translationData( $langcode )['title'] . '"' );
          } else {
            static::addComment( $r, '(' . $langcode . ') "' . $p->title() . '" (translationData FUBAR)' );
          }
        }
      }

      // don't include the error page
      if ( $p->isErrorPage() ) {
        static::addComment( $r, 'excluding because ERRORPAGE' );
        continue;
      }

      if ( $p->status() == 'unlisted' && ! $p->isHomePage() ) {
        if ( isset( static::$optionIUWSI ) && in_array( $p->slug(), static::$optionIUWSI, false ) ) {
          static::addComment( $r, 'including because unlisted but in includeUnlistedWhenSlugIs' );
        } else {
          static::addComment( $r, 'excluding because unlisted' );
          continue;
        }
      }

      // exclude because template used is in the exclusion list:
      if ( isset( static::$optionXPWTI ) && in_array( $p->intendedTemplate(), static::$optionXPWTI, false ) ) {
        static::addComment( $r, 'excluding ' . $p->url() . ' because excludePageWhenTemplateIs (' . $p->intendedTemplate() . ')' );
        continue;
      }

      // exclude because slug is in the exclusion list:
      if ( isset( static::$optionXPWSI ) && in_array( $p->slug(), static::$optionXPWSI, false ) ) {
        static::addComment( $r, 'excluding because excludePageWhenSlugIs (' . $p->slug() . ')' );
        continue;
      }

      // exclude because page content field 'excludefromxmlsitemap':
      if ( $p->content()->excludefromxmlsitemap() == 'true' ) {
        static::addComment( $r, 'excluding because excludeFromXMLSitemap' );
        continue;
      }

      // exclude because, if supported, the page is sunset:
      if ( $p->hasMethod( 'issunset' ) ) {
        if ( $p->issunset() ) {
          static::addComment( $r, 'excluding because isSunset' );
          continue;
        }
      }

      // exclude because, if supported,  the page is under embargo
      if ( $p->hasMethod( 'isunderembargo' ) ) {
        if ( $p->isunderembargo() ) {
          static::addComment( $r, 'excluding because isUnderembargo' );
          continue;
        }
      }

      // <loc>https://www.example.com/slug</loc>

      $r .= "<url>\n";
      if ( $langcode == null ) { // single-language
        $r .= '  <loc>' . $p->url() . '</loc>' . "\n";
      } else {
        // Do NOT do urlForLanguage for the default language - bad things will happen - see k-next/kirby#1169
        if ( $langcode == "--" ) { // ml - default language
           $r .= '  <loc>' . $p->url() . '</loc>' . "\n";
        } else {
          $r .= '  <loc>' . $p->urlForLanguage( $langcode ) . '</loc>' . "\n";
        }
        // default language: <xhtml:link rel="alternate" hreflang="x-default" href="http://www.example.com/"/>
        $r .= '  <xhtml:link rel="alternate" hreflang="x-default" href="' . $p->urlForLanguage( kirby()->language()->code() ) . '" />' . "\n";
        // localized languages: <xhtml:link rel="alternate" hreflang="en" href="http://www.example.com/"/>
        foreach ( $p->translations() as $tr ) {
          $r .= '  <xhtml:link rel="alternate" hreflang="' . $tr->code() . '" href="' . $p->urlForLanguage( $tr->code() ) . '" />' . "\n";
        }
      }//end if

      // priority for determining the last modified date: updatedat, then date, then filestamp
      // default to unix epoch (jan-1-1970) if not found
      $lastmod = static::getLastmod( $p, $langcode );

      // set modified date to be last date vis-a-vis when file modified /content embargo time / content date
      $r .= '  <lastmod>' . date( 'c', $lastmod ) . "</lastmod>\n";

      /*
          Don't bother with priority - we ignore those. It's essentially a bag of noise" - [ref https://twitter.com/methode/status/846796737750712320]
          if ($p->depth()==1)
          $r.="  <priority>". ($p->isHomePage() ? "1.0" : "0.9") . "</priority>\n";
          if ($p->depth()>=2)
          $r.="  <priority>0.8</priority>\n";
      */

      if ( static::$optionNOIMG != true ) {
        static::addImagesFromPageToSitemap( $p, $r );
      }

      if ( $p->children() !== null ) {
        // jump into the children, unless the current page's template is in the exclude-its-children set
        if ( isset( static::$optionXCWTI ) && in_array( $p->intendedTemplate(), static::$optionXCWTI, false ) ) {
          static::addComment( $r, 'ignoring child pages but not child images because excludeChildrenWhenTemplateIs (' . $p->intendedTemplate() . ')' );
          if ( static::$optionNOIMG != true ) {
            static::addImagesToSitemap( $p->children(), $r );
          }

          $r .= "</url>\n";
        } else {
          $r .= "</url>\n";
          static::addPagesToSitemap( $p->children(), $r, $langcode );
        }
      } else {
        $r .= "</url>\n";
      }//end if
    }//end foreach
  }//end addPagesToSitemap()

  private static function getLastmod( Page $p, ?string $langcode = null ) : int {
    $lc = $langcode;
    if ( $lc == '--' ) {
      $lc = null;
    }

    $lastmod = 0; // default to unix epoch (jan-1-1970)
    if ( $p->content( $lc )->has( 'updatedat' ) ) {
      $t       = $p->content( $lc )->get( 'updatedat' );
      $lastmod = strtotime( $t );
    } else {
      if ( $p->content( $lc )->has( 'date' ) ) {
        $t       = $p->content( $lc )->get( 'date' );
        $lastmod = strtotime( $t );
      } else {
        if ( file_exists( $p->contentFile( $lc ) ) ) {
          $lastmod = filemtime( $p->contentFile( $lc ) );
        }
      }
    }

    // ML: Failsafe fallback to default language if not already there
    if ( $lastmod == false ) {
      if ( $langcode != null && $langcode != '--' ) {
        return static::getLastmod( $p, '--' );
      } else {
        return 0;
      }
    }
    return $lastmod;
  }//end getLastmod()

  private static function addComment( string &$r, string $m ) : void {
    if ( static::$debug == true ) {
      $r .= '<!-- ' . $m . " -->\n";
    }
  }//end addComment()

  private static function addImagesFromPageToSitemap( Page $page, string &$r ) : void {
    foreach ( $page->images() as $i ) {
      $r .= "  <image:image>\n";
      $r .= '    <image:loc>' . $i->url() . "</image:loc>\n";
      $r .= "  </image:image>\n";
    }
  }//end addImagesFromPageToSitemap()

  private static function addImagesToSitemap( Pages $pages, string &$r ) : void {
    foreach ( $pages as $p ) {
      static::addComment( $r, 'imagining ' . $p->url() . ' [it=' . $p->intendedTemplate() . '] [d=' . $p->depth() . ']' );
      static::addImagesFromPageToSitemap( $p, $r );
    }
  }//end addImagesToSitemap()

  public function getNameOfClass() : string {
    return static::class;
  }//end getNameOfClass()
}//end class
