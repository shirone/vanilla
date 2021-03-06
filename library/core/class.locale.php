<?php if (!defined('APPLICATION')) exit();

/**
 * The Locale class is used to load, define, change, and render translations
 * for different locales. It is a singleton class.
 *
 * @author Mark O'Sullivan <mark@vanillaforums.com>
 * @copyright 2003 Vanilla Forums, Inc
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @package Garden
 * @since 2.0
 */

class Gdn_Locale extends Gdn_Pluggable {

   /**
    * The name of the currently loaded Locale
    * @var string
    */
   public $Locale = '';

   /**
    * Holds all locale sources
    * @var Gdn_Configuration
    */
   public $LocaleContainer = NULL;

   /**
    * Whether or not to record core translations
    * @var boolean
    */
   public $DeveloperMode = FALSE;

   /**
    * Core translations, and untranslated codes
    * @var Gdn_Configuration
    */
   public $DeveloperContainer = NULL;

   public static $SetLocales = array(
      'bg' => 'bg_BG',
      'bs' => 'bs_BA',
      'ca' => 'ca_ES',
      'cs' => 'cs_CZ',
      'da' => 'da_DK',
      'de' => 'de_DE',
      'el' => 'el_GR',
      'en' => 'en_US',
      'es' => 'es_ES',
      'fa' => 'fa_IR',
      'fr' => 'fr_FR',
      'he' => 'he_IL',
      'hi' => 'hi_IN',
      'hu' => 'hu_HU',
      'id' => 'id_ID',
      'it' => 'it_IT',
      'ja' => 'ja_JP',
      'ko' => 'ko_KR',
      'lt' => 'lt_LT',
      'my' => 'my_MM',
      'nb' => 'nb_NO',
      'no' => array('no_NO', 'nn_NO'),
      'nl' => 'nl_NL',
      'pl' => 'pl_PL',
      'pt' => 'pt_BR',
      'ro' => 'ro_RO',
      'ru' => 'ru_RU',
      'sv' => 'sv_SE',
      'th' => 'th_TH',
      'tr' => 'tr_TR',
      'uk' => 'uk_UA',
      'vi' => 'vi_VN',
      'zh' => 'zh_CN'
   );

   public $SavedDeveloperCalls = 0;

   public function __construct($LocaleName, $ApplicationWhiteList, $PluginWhiteList, $ForceRemapping = FALSE) {
      parent::__construct();
      $this->ClassName = 'Gdn_Locale';

      $this->Set($LocaleName, $ApplicationWhiteList, $PluginWhiteList, $ForceRemapping);
   }

   /**
    * Canonicalize a locale string so different representations of the same locale can be used together.
    *
    * Example:
    *
    *     echo Gdn_Locale::Canonicalize('en-us');
    *     // prints en_US
    *
    * @param string $locale The locale code to canonicalize.
    * @return string Returns the canonicalized version of the locale code.
    */
   public static function Canonicalize($locale) {
      $locale = str_replace(array('-', '@'), array('_', '__'), $locale);
      $parts = explode('_', $locale, 2);
      if (isset($parts[1])) {
         $parts[1] = strtoupper($parts[1]);
      }
      $result = implode('_', $parts);
      // Remove everything from the string except letters, numbers, dashes, and underscores.
      $result = preg_replace('/([^\w-])/', '', $result);

      // This is a bit of a kludge, but we are deprecating en_CA in favour of just en.
      if ($result === 'en_CA') {
         $result = 'en';
   }

      return $result;
   }

   /**
    * Reload the locale system
    */
   public function Refresh() {
      $LocalName = $this->Current();

      $ApplicationWhiteList = Gdn::ApplicationManager()->EnabledApplicationFolders();
      $PluginWhiteList = Gdn::PluginManager()->EnabledPluginFolders();

      $ForceRemapping = TRUE;

      $this->Set($LocalName, $ApplicationWhiteList, $PluginWhiteList, $ForceRemapping);
   }

   public function SaveTranslations($Translations, $LocaleName = FALSE) {
      $this->LocaleContainer->Save();
   }

   /**
    * Defines and loads the locale.
    *
    * @param string $LocaleName The name of the locale to load. Locale definitions are kept in each
    * application's locale folder. For example:
    *  /dashboard/locale/$LocaleName.php
    *  /vanilla/locale/$LocaleName.php
    * @param array $ApplicationWhiteList An array of application folders that are safe to examine for locale
    *  definitions.
    * @param array $PluginWhiteList An array of plugin folders that are safe to examine for locale
    *  definitions.
    * @param bool $ForceRemapping For speed purposes, the application folders are crawled for locale
    *  sources. Once sources are found, they are saved in the
    *  cache/locale_mapppings.php file. If ForceRemapping is true, this file will
    *  be ignored and the folders will be recrawled and the mapping file will be
    *  re-generated. You can also simply delete the file and it will
    *  automatically force a remapping.
    */
   public function Set($LocaleName, $ApplicationWhiteList, $PluginWhiteList, $ForceRemapping = FALSE) {
      $CurrentLocale = self::Canonicalize($LocaleName);

      // Get locale sources
      $this->Locale = $CurrentLocale;
      $LocaleSources = $this->GetLocaleSources($CurrentLocale, $ApplicationWhiteList, $PluginWhiteList, $ForceRemapping);

      $Codeset = C('Garden.LocaleCodeset', 'UTF8');

      $SetLocale = array(
         LC_TIME,
         "$CurrentLocale.$Codeset",
         $CurrentLocale
      );

      list($Language) = explode('_', $CurrentLocale, 2);
      if (isset(self::$SetLocales[$Language])) {
         $FullLocales = (array)self::$SetLocales[$Language];

         foreach ($FullLocales as $FullLocale) {
            $SetLocale[] = "$FullLocale.$Codeset";
            $SetLocale[] = $FullLocale;
         }
      }

      $r = call_user_func_array('setlocale', $SetLocale);

      if (!is_array($LocaleSources)) {
         $LocaleSources = array();
      }

      // Create a locale config container
      $this->Unload();

      $ConfLocaleOverride = PATH_CONF.'/locale.php';
      $Count = count($LocaleSources);
      for ($i = 0; $i < $Count; ++$i) {
         if ($ConfLocaleOverride != $LocaleSources[$i] && file_exists($LocaleSources[$i])) // Don't double include the conf override file... and make sure it comes last
            $this->Load($LocaleSources[$i], FALSE);
      }

      // Also load any custom defined definitions from the conf directory
      if (file_exists($ConfLocaleOverride))
         $this->Load($ConfLocaleOverride, TRUE);

      // Prepare developer mode if needed
      $this->DeveloperMode = C('Garden.Locales.DeveloperMode', FALSE);
      if ($this->DeveloperMode) {
         $this->DeveloperContainer = new Gdn_Configuration();
         $this->DeveloperContainer->Splitting(FALSE);
         $this->DeveloperContainer->Caching(FALSE);

         $DeveloperCodeFile = PATH_CACHE."/locale-developer-{$LocaleName}.php";
         if (!file_exists($DeveloperCodeFile))
            touch($DeveloperCodeFile);

         $this->DeveloperContainer->Load($DeveloperCodeFile, 'Definition', TRUE);
      }

      // Import core (static) translations
      if ($this->DeveloperMode)
         $this->DeveloperContainer->MassImport($this->LocaleContainer->Get('.'));

      // Allow hooking custom definitions
      $this->FireEvent('AfterSet');
   }

   /**
    * Crawl applications or plugins for its locale files.
    *
    * @param string $basePath The base path. Either the plugins or applications path.
    * @param string[] $folders The folders to crawl within the base path.
    * @param array $result The result array to put all the translation paths.
    */
   protected function CrawlAddonLocaleSources($basePath, $folders, &$result) {
      if (!is_array($folders)) {
         return;
      }

      $paths = array();
      foreach ($folders as $folder) {
         $paths[] = $basePath."/$folder/locale";
      }

      // Get all of the locale files for the addons.
      foreach ($paths as $path) {
         // Look for individual locale files.
         $localePaths = SafeGlob($path.'/*.php');
         foreach ($localePaths as $localePath) {
            $locale = self::Canonicalize(basename($localePath, '.php'));
            $result[$locale][] = $localePath;
         }

         // Look for locale files in a directory.
         // This should be deprecated very soon.
         $localePaths = SafeGlob($path.'/*/definitions.php');
         foreach ($localePaths as $localePath) {
            $locale = self::Canonicalize(basename(dirname($localePath)));
            $result[$locale][] = $localePath;

            $subPath = StringBeginsWith($localePath, PATH_ROOT, true, true);
            $properPath = dirname($subPath).'.php';

            trigger_error("Locales in $subPath is deprecated. Use $properPath instead.", E_USER_DEPRECATED);
         }
      }
   }

   /**
    * Crawl the various addons and locales for all of the applicable translation files.
    *
    * @param string[] $applicationWhiteList An array of enabled application folders.
    * @param string[] $pluginWhiteList An array of enabled plugin folders.
    * @return array Returns an array keyed by locale names where each value is an array of translation paths for that locale.
    */
   public function CrawlAllLocaleSources($applicationWhiteList, $pluginWhiteList) {
      $result = array();

      // Get all of the locale files for the applications.
      $this->CrawlAddonLocaleSources(PATH_APPLICATIONS, $applicationWhiteList, $result);

         // Get locale-based locale definition files.
      $enabledLocales = C('EnabledLocales');
      if (is_array($enabledLocales)) {
         foreach ($enabledLocales as $localeKey => $locale) {
            $locale = self::Canonicalize($locale);

               // Grab all of the files in the locale's folder.
            $translationPaths = SafeGlob(PATH_ROOT."/locales/{$localeKey}/*.php");
            foreach($translationPaths as $translationPath) {
               $result[$locale][] = $translationPath;
                  }
               }
            }

      // Get all of the locale files for plugins.
      // Notice that the plugins are processed here so that they have overriding power.
      $this->CrawlAddonLocaleSources(PATH_PLUGINS, $pluginWhiteList, $result);

      // Get theme-based locale definition files.
      $theme = C('Garden.Theme');
      if ($theme) {
         $this->CrawlAddonLocaleSources(PATH_THEMES, array($theme), $result);
         }

      // Look for a global locale.
      $configLocale = PATH_CONF.'/locale.php';
      if (file_exists($configLocale)) {
         foreach (array_keys($result) as $locale) {
            $result[$locale][] = $configLocale;
         }
         }

      // Look for locale specific config locales.
      $paths = SafeGlob(PATH_CONF.'/locale-*.php');
      foreach ($paths as $path) {
         if (preg_match('`^locale-([\w-]+)$`i', basename($path, '.php'), $matches)) {
            $locale = self::Canonicalize($matches[1]);
            $result[$locale][] = $path;
         }
         }

      return $result;
         }

   /**
    * Gets the locale sources for a given locale.
    *
    * @param string $locale The name of the locale.
    * @param string[] $applicationWhiteList An array of enabled application folders.
    * @param string[] $pluginWhiteList An array of enabled plugin folders.
    * @param bool $forceRemapping Whether or not to force a rebuild of the cache.
    * @return array Returns an array of paths to the translations for the locale.
    */
   public function GetLocaleSources($locale, $applicationWhiteList, $pluginWhiteList, $forceRemapping = FALSE) {
      $safeLocale = static::Canonicalize($locale);

      // First try and grab the locale sources from the cache.
      Gdn_LibraryMap::PrepareCache('locale', null, 'tree');
      $allLocaleSources = Gdn_LibraryMap::GetCache('locale');

      if ($forceRemapping || !Gdn_LibraryMap::CacheReady('locale') || $allLocaleSources === null) {
         // Build the entire locale sources array and cache it.
         $allLocaleSources = $this->CrawlAllLocaleSources($applicationWhiteList, $pluginWhiteList);
         Gdn_LibraryMap::PrepareCache('locale', $allLocaleSources);
      }

      $localeSources = val($safeLocale, $allLocaleSources, array());
      return $localeSources;
   }

   /**
    * Load a locale definition file.
    *
    * @param string $Path The path to the locale.
    * @param boolean $Dynamic Whether this locale file should be the dynamic one.
    */
   public function Load($Path, $Dynamic = FALSE) {
      $this->LocaleContainer->Load($Path, 'Definition', $Dynamic);
   }

   /**
    * Assigns a translation code.
    *
    * These DO NOT PERSIST.
    *
    * @param mixed $Code The code to provide a translation for, or an array of code => translation
    * values to be set.
    * @param string $Translation The definition associated with the specified code. If $Code is an array
    *  of definitions, this value will not be used.
    */
   public function SetTranslation($Code, $Translation = '', $Save = FALSE) {
      if (!is_array($Code))
         $Code = array($Code => $Translation);

      $this->LocaleContainer->SaveToConfig($Code, NULL, $Save);
   }

   /**
    * Translates a code into the selected locale's definition.
    *
    * @param string $Code The code related to the language-specific definition.
    *   Codes thst begin with an '@' symbol are treated as literals and not translated.
    * @param string $Default The default value to be displayed if the translation code is not found.
    * @return string
    */
   public function Translate($Code, $Default = FALSE) {
      if ($Default === FALSE)
         $Default = $Code;

      // Codes that begin with @ are considered literals.
      if (substr_compare('@', $Code, 0, 1) == 0)
         return substr($Code, 1);

      $Translation = $this->LocaleContainer->Get($Code, $Default);

      // If developer mode is on, and this translation returned the default value,
      // remember it and save it to the developer locale.
      if ($this->DeveloperMode && $Translation == $Default) {
         $DevKnows = $this->DeveloperContainer->Get($Code, FALSE);
         if ($DevKnows === FALSE)
            $this->DeveloperContainer->SaveToConfig($Code, $Default);
      }

      return $Translation;
   }

   /**
    *  Clears out the currently loaded locale settings.
    */
   public function Unload() {
      // If we're unloading, don't save first
      if ($this->LocaleContainer instanceof Gdn_Configuration)
         $this->LocaleContainer->AutoSave(FALSE);

      $this->LocaleContainer = new Gdn_Configuration();
      $this->LocaleContainer->Splitting(FALSE);
      $this->LocaleContainer->Caching(FALSE);
   }

   /**
    * Returns the name of the currently loaded locale.
    *
    * @return boolean
    */
   public function Current() {
      if ($this->Locale == '')
         return FALSE;
      else
         return $this->Locale;
   }

   /**
    * Search the garden/locale folder for other locale sources that are
    * available. Returns an array of locale names.
    *
    * @return array
    */
   public function GetAvailableLocaleSources() {
      return Gdn_FileSystem::Folders(PATH_APPLICATIONS.'/dashboard/locale');
   }

   /**
    * Get all definitions from the loaded locale
    */
   public function GetDefinitions() {
      return $this->LocaleContainer->Get('.');
   }

   /**
    * Get all known core
    */
   public function GetDeveloperDefinitions() {
      if (!$this->DeveloperMode) return FALSE;

      return $this->DeveloperContainer->Get('.');
   }

}
