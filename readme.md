vBulletin bridge for Laravel 4
================================
### This is a Laravel Composer package providing a method to authenticate against a vBulletin database to allow for Laravel
sites to be built beside a vBulletin forum without requiring users to create new accounts.

Add `eld/bridgevb` as a requirement in composer.json:  
```javascript
{
	"require": {
		"eld/bridgevb": "1.*"
	}
}
```
Run `composer update` to update your packages or `composer install` if you haven't already run the command

To use the package, add this line to your providers array contained in the app/config/app.php file:  
```php
'providers' => array(
				...
				...
				'Eld\BridgeVb\BridgeVbServiceProvider'
),
```

In order to use the facade, add the following to your aliases array in app/config/app.php:  
```php
'aliases' => array(
				...
				...
				'Bridgevb'		  => 'Eld\Bridgevb\Facades\BridgeVb',
),
```

Configuration File
==================
The default configuration file is found in `vender/eld/bridgevb/src/config/config.php` but you should overwrite it with a `app/config/packages/eld/bridgevb/config.php` file.

Usage
=====