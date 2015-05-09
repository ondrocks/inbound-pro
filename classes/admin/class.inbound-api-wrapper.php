<?php

/**
 *
 *	Manage Inbound Templates
 *
*/


class Inbound_API_Wrapper {

	static $inbound_api_uri;
	static $docs_uri = 'http://docs.inboundnow.com/feed?post_type=doc-page';
	static $blog_uri = 'http://www.inboundnow.com/feed/';
	static $data;
	static $templates;
	static $extensions;
	static $remote_content; /* dataset of remote inbound now article/social content */
	static $blogs; /* dataset of blog */
	static $docs;
	static $response;

    /**
     * Get API Endpoits
     */
     public function __construct() {
        self::get_api_url();
     }

    /**
     * Get API URL base
     */
     public static function get_api_url() {
         if ( strstr( site_url() , 'inboundsoon.dev')) {
             self::$inbound_api_uri = 'http://localhost:3001/api/';
         } else {
             self::$inbound_api_uri = 'http://api.inboundnow.com/api/';
         }

         return self::$inbound_api_uri;
     }
    /**
     * Get license key
     */
    public static function get_api_key() {
        $settings_values = Inbound_Options_API::get_option( 'inbound-pro' , 'settings' , array() );
        return (isset($settings_values['api-key']['api-key'])) ? trim($settings_values['api-key']['api-key']) : '';
    }

	/**
	*  Gets data array of available products
	*/
	public static function get_downloads() {

		/* check history first for cached object */
		self::$data = Inbound_Options_API::get_option( 'inbound-api' , 'downloads' , array() );

		/* if data is not set to expire then do not update */
		if ( isset(self::$data['expire']) && self::$data['expire'] > gmdate( 'Y-m-d G:i:s' ) ) {
			return;
		}

		/* This call home gets a list of available downloads - We have minimum security because no sensitive information is revealed */
		$response = wp_remote_post( 'http://www.inboundnow.com' , array(  'body' => array ( 'get_downloads' => true , 'key' => 'hudson11' ) ) );

		/* unserialize response */
		self::$data = unserialize( $response['body'] );

		/* build new expiration date */
		self::$data['expire'] = gmdate('Y-m-d G:i:s' , strtotime( "+6 hours" ));

		/* update data object */
		Inbound_Options_API::update_option( 'inbound-api' , 'downloads' , self::$data );
	}

	/**
	*  Get latest docs
	*/
	public static function get_docs() {
		/* check history first for cached object */
		self::$remote_content = Inbound_Options_API::get_option( 'inbound-api' , 'remote' , array() );

		/* if data is not set to expire then do not update */
		if ( isset(self::$remote_content['docs']['expire']) && self::$remote_content['docs']['expire'] > gmdate( 'Y-m-d G:i:s' ) ) {
			return self::$remote_content['docs']['items'];
		}

		/* This call home gets a list of available downloads - We have minimum security because no sensitive information is revealed */
		$response = wp_remote_get( self::$docs_uri );

		if (is_wp_error($response) || !isset($response['body'])) {
			return;
		}

		/* unserialize response */
		$xml = simplexml_load_string( $response['body'] , null  , LIBXML_NOCDATA );
		$json = json_encode($xml);
		self::$docs = json_decode($json,TRUE);

		/* build new expiration date */
		self::$remote_content['docs']['expire'] = gmdate('Y-m-d G:i:s' , strtotime( "+3 hours" ));

		/* build doc dataset */
		self::$remote_content['docs']['items'] = self::$docs['channel']['item'];

		/* update data object */
		Inbound_Options_API::update_option( 'inbound-api' , 'remote' , self::$remote_content );

		return self::$docs['channel']['item'];
	}

	/**
	*  Get latest blog posts
	*/
	public static function get_blog_posts() {
		/* check history first for cached object */
		self::$remote_content = Inbound_Options_API::get_option( 'inbound-api' , 'remote' , array() );

		/* if data is not set to expire then do not update */
		if ( isset(self::$remote_content['blog']['expire']) && self::$remote_content['blog']['expire'] > gmdate( 'Y-m-d G:i:s' ) ) {
			return self::$remote_content['blog']['items'];
		}

		/* This call home gets a list of available downloads - We have minimum security because no sensitive information is revealed */
		$response = wp_remote_get( self::$blog_uri );

		/* unserialize response */
		$xml = simplexml_load_string( $response['body'] , null  , LIBXML_NOCDATA );
		$json = json_encode($xml);
		self::$blogs = json_decode($json,TRUE);

		/* build new expiration date */
		self::$remote_content['blog']['expire'] = gmdate('Y-m-d G:i:s' , strtotime( "+3 hours" ));

		/* build doc dataset */
		self::$remote_content['blog']['items'] = self::$blogs['channel']['item'];

		/* update data object */
		Inbound_Options_API::update_option( 'inbound-api' , 'remote' , self::$remote_content );

		return self::$blogs['channel']['item'];
	}

	/**
	*  Returns templates from dataset
	*  @return ARRAY
	*/
	public static function get_pro_templates() {

		self::get_downloads();
		self::$templates = array();

		foreach ( self::$data as $key => $download ) {

			if ( isset($download->download_type) && $download->download_type == 'template' ) {
				self::$templates[] = (array) $download;
			}

		}

		return self::$templates;
	}

	/**
	*  Returns extensions from dataset
	*  @return ARRAY
	*/
	public static function get_pro_extensions() {
		self::get_downloads();
		self::$extensions = array();

		foreach ( self::$data as $key => $download ) {
			if ( isset($download->download_type) && $download->download_type == 'extension' ) {
				self::$extensions[] = (array) $download;
			}
		}

		return self::$extensions;
	}

	/**
	*  Get latest blog posts
	*/
	public static function get_inboundnow_blog_posts() {

	}

    /**
     * Get the pro download url
     */
    public static function get_pro_info_endpoint() {
        return self::get_api_url().'pro/info';
    }


	/**
	*  Get download zip file from inbound now
	*/
	public static function get_download_zip( $download ) {

		/* get license key */
		$license_key = self::get_api_key();

		/* get domain */
		$domain = site_url();

		$response = wp_remote_post( self::$inbound_api_uri.'downloads/zip' , array(
			'method' => 'POST',
			'timeout' => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking' => true,
			'headers' => array(),
			'body' => array(
				'filename' => $download['filename'],
				'type' => $download['type'],
				'site' => $domain,
				'api' => $license_key
			)
		));

		/* print error if wp_remote_post has error */
		if ( is_wp_error( $response ) ) {
		   $error_message = $response->get_error_message();
		   echo "Something went wrong: $error_message";
		   exit;
		}

		/* decode response from body */
		$json = $response['body'];
		$array = json_decode($json, true);

		/* if error show error message and die */
		if(isset($array['error']) || !isset( $array['url'] )) {
			print_r($array); exit;
		}

		return $array['url'];

	}


}


new Inbound_API_Wrapper;