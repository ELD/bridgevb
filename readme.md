vBulletin 4 bridge for Laravel 4
================================
### This is a Laravel Composer package providing a method to authenticate against a [vBulletin 4.x](http://www.vbulletin.com) database to allow for [Laravel 4](http://laravel.com) sites to be built beside a vBulletin forum without requiring users to create new accounts.

This package is based off of the pperon/vbauth package and the vb_auth package for CodeIgnitor.

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
				'Eld\Bridgevb\BridgevbServiceProvider',
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

Configuration File:
===================
The default configuration file is found in `vender/eld/bridgevb/src/config/config.php` but you should overwrite it with a `app/config/packages/eld/bridgevb/config.php` file.  

The configuration file looks like:
```php
array(
	'connection' => 'mysql',
	'cookie_hash' => 'AdflkjEr90234asdlkj1349SDFkl',
	'cookie_prefix' => 'bb_',
	'db_prefix' => 'vb_',
	'forum_path' => 'http://example.com/',
	'user_groups' => array(
		'Admin' => array(6),
		'Moderator' => array(7),
		'Super Moderator' => array(5),
		'User' => array(2),
		'Banned' => array(8),
		'Guest' => array(3),
	),
	'user_columns' => array(
		'userid',
		'username',
		'password',
		'usergroupid',
		'membergroupids',
		'email',
		'salt'
	),
);
```
You need to replace the following fields in the existing config file or the config file in `app/config/packages/eld/bridgevb/config.php` file to suit your setup: `connection`, `cookie_hash`, `cookie_prefix`, `db_prefix`, `db_prefix`, `forum_path`, and add your forum's user groups in the `user_groups` portion and customize which user info you'd like to fetch with the `user_columns` option.


Exampe Usage:
==============
This is what a filter could look like in `app/filters.php` if you were to authenticate users on every page they loaded.
```php
Route::filter('vbauth', function()
{
	if (!Bridgevb::isLoggedIn()) return Redirect::to('login');
});
```

If you wanted to log in users externally from the vBulletin site, here's an snippet of how you'd do it:

```php
Route::post('/login', function()
{
	$creds = array(
		'username' => Input::get('username'),
		'password' => Input::get('password'),
		'remember_me' => Input::get('remember_me', false),
	);

	if (Bridgevb::attempt($creds))
		return Redirect::to('/');
	else
		return Redirect::to('/login');
});
```

### The following are functions included in the library:
Examples of usage are included in each description of the method

#### is()
The is() function takes in a string parameter an returns whether the user is a member of that user group.
```php
Route::get('/isgroup', function()
{
	return (Bridgevb::is('Banned') ? 'You are banned' : 'You are not banned');
});
```

#### attempt()
The attempt method takes in an array of credentials and returns true if the authentication attempt was successful and returns false if the credentials were incorrect.
```php
Route::post('/login', function()
{
	$creds = array(
		'username' => Input::get('username'),
		'password' => Input::get('password'),
		'remember_me' => Input::get('remember_me', false),
	);

	if (Bridgevb::attempt($creds))
		return Redirect::to('/');
	else
		return Redirect::to('/login');
});
```

#### isLoggedIn()
The isLoggedIn() function checks to authenticate the current user and retursn a non-zero value if the user is currently authenticated.
```php
Route::get('/login', function()
{
	if(Bridgevb::isLoggedIn())
		return Redirect::to('/');
	return View::make('login');
});
```

#### getUserInfo()
The getUserInfo() function returns a stdClass object representing the user data retrieved from the vBulletin user table as specified in the configuration file.
```php
$user = Bridgevb::getUserInfo();
echo 'Hello, ' . $user->username;
```

#### get()
The get method takes in a string and returns the particular piece of information about the user that's passed in.
```php
$username = Bridgevb::get('username');
echo 'Hello, ' . $username;
```

#### getLogoutHash()
This function returns the logout hash for the user logged in that allows for you to link to vBulletin's logout function with teh proper logout hash.
```php
return '<a href="http://www.example.com/login.php?do=logout&logouthash=' . Bridgevb::getLogoutHash() . '">Logout</a>';
```

#### logout()
Logs the user out manually instead of routing through vBulletin's logout function.
```php
Bridgevb::logout();
```

Changelog
=========
6/1/2013 - Initial Release of v1.0