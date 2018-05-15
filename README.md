# WC Orders App WP API extension 


A simple WP plugin which adds  a possibility to send 
REST API calls to your site by using
 [JSON Web Tokens](https://jwt.io/). The plugin uses
 [Firebase JWT](https://github.com/firebase/php-jwt)
 lib for token generation and validation.
 
 Whereas this plugin is not very useful in its 
 current state, it could serve you as boilerplate
 if you are building REST API for your site and
 would like to use JWT authentication. So, feel
 free to fork it and extend anything you like ;)
 
 ## Usage
 
 ### Plugin
 Download as zip archive or git clone the content
 of the repo to `uni-wc-orders-app` folder and 
 place this folder within 'plugins' folder of your 
 WP installation. Activate the plugin.
 
 ### Set a secret key
 Locate where the constant 
 `WC_ORDERS_APP_SECRET_KEY` is being set in 
 the plugin's code and
 change this value to smth unique. This key is used
 for signing JWT token and should be kept in 
 secret.
 
 ### Use endpoints
 
 ##### A token
 Method: `POST`
 
 Params: `username`, `password`
 
 Endpoint: `your-site.com/uni-app/v1/token`

Returns a token `string`  or auth error.
 

 ##### List of orders
 Method: `GET`
 
 Params: `Authorization` header should be sent with
 value `Bearer <token>`
 
 Endpoint: `your-site.com/uni-app/v1/orders`

 Returns list of orders `array` of `objects` 
 or auth error. Just an example of arbitrary chosen
 data for each order. You may want to adjust this.

 ### Important
 Only SSL connections to endpoints are allowed!