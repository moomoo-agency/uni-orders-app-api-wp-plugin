# WC Orders App WP API extension 


A simple WP plugin which adds  a possibility to send 
REST API calls to your site by using
 [JSON Web Tokens](https://jwt.io/). The plugin uses
 [Firebase JWT](https://github.com/firebase/php-jwt)
 lib for token generation and validation.
 
 This plugin has a particular usage case as well. It 
 helps getting the list of WooCommerce orders via REST
 API. Has endpoints for updating, editing and deleting
 orders as well as batch update orders. It also has
 an endpoint for getting sales reports.
 
 The most methods are derived from original WooCommerce
 API. However, the actual orders response has changed to
 serve my needs. In particular, order items' meta
 output changed to display Uni CPO's custom options
 information. Have you heard of 
 [Uni CPO](https://builderius.io/cpo/) plugin yet? Well,
 you definitely should have ;)
 
 This plugin could serve you as a boilerplate
 if you are building REST API for your site and
 wondering how/would like to use JWT authentication. 
 So, feel free to fork it and extend anything you like ;)
 
 ## Usage
 
  ### Important
  Make sure you have installed and activated WooCommerce 
  plugin! 
  
 
  ### Plugin
 Download as zip archive or git clone the content
 of the repo to `uni-wc-orders-app` folder and 
 place this folder within 'plugins' folder of your 
 WP installation. Activate the plugin.
 
 ### Set a secret key
 Locate where the constant 
 `WC_ORDERS_APP_SECRET_KEY` is being defined. It looks like this:
 
  `$this->define( 'WC_ORDERS_APP_SECRET_KEY', 'your-secret-key-here' );`
 
 Change the key to something unique. This key is used
 for signing JWT token and should be kept in 
 secret.
 
 ### Use endpoints
 
  ### Important
  Only SSL connections to endpoints are allowed!
 
 ##### Authorization
 Method: `POST`
 
 Endpoint: `/wp-json/uni-app/v1/token`
 
 Data (example):
 
 `
{
	"username": "admin",
	"password": "password"
}
 `

Returns a token `string`  or auth error.
 

 ##### List of orders
 Method: `GET`
 
 Params: `Authorization` header should be sent with
 value `Bearer <token>`
 
 Endpoint: `/wp-json/uni-app/v1/orders`

 Returns list of orders `array` of `objects` 
 or auth error. Just an example of arbitrary chosen
 data for each order. You may want to adjust this.
 
  ##### Get order by ID
  Method: `GET`
  
  Params: `Authorization` header should be sent with
  value `Bearer <token>`
  
  Endpoint: `/wp-json/uni-app/v1/orders/<id>`
  
  Returns a specific order as JSON object.
  
  ##### Update order by ID
  Method: `PUT`
  
  Params: `Authorization` header should be sent with
  value `Bearer <token>`
  
  Endpoint: `/wp-json/uni-app/v1/orders/<id>`
  
  Data (example):
   
   `
  {
  	"status": "processing"
  }
  `
  
  Updates a specific order. Returns a new order data 
  as JSON object.
  
  ##### Batch update orders
  Method: `POST`
  
  Params: `Authorization` header should be sent with
  value `Bearer <token>`
  
  Endpoint: `/wp-json/uni-app/v1/orders/batch`
  
  Data (example):
   
   `
   {
        "update": [
            {
                "id": 11,
  	            "status": "processing"
            },
            {
                "id": 15,
              	"status": "completed"
            }
        ]
   }
  `
  
  Batch create, update and delete multiple orders.
  
 ##### Get a sales report
 Method: `GET`
 
 Params: `Authorization` header should be sent with
 value `Bearer <token>`; `date_min` - a specific 
 start date, the date need to be in the YYYY-MM-DD 
 format; `date_max` - a specific end date, the date 
 need to be in the YYYY-MM-DD format;
   
 
 Endpoint: `/wp-json/uni-app/v1/reports/sales`

 Returns an array with one element which is a JSON 
 object with a sales report data.