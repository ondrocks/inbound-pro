<?php
/*
Plugin Name: Inbound Extension - MailPoet Integration
Plugin URI: http://www.inboundnow.com/
Description: Integrates lead lists with mailpoet lists
Version: 1.0.1
Author: Inbound Now
Author URI: http://www.inboundnow.com/

*/


if (!class_exists('Inbound_Mailpoet')) {

class Inbound_Mailpoet {
	
	static $api;
	static $license_key;
	static $wordpress_url;
	
	/**
	* Initialize Inbound_Mailpoet Class
	*/
	public function __construct() {	
		//delete_option( 'inbound_ignore_mailpoet_notice');exit;
		self::define_constants();
		self::load_hooks();		
	}
	
	/**
	* Define Constants
	*  
	*/
	private static function define_constants() {
		define('INBOUND_MAILPOET_CURRENT_VERSION', '1.0.5' ); 
		define('INBOUND_MAILPOET_LABEL' , 'MailPoet Integration' ); 
		define('INBOUND_MAILPOET_SLUG' , plugin_basename( dirname(__FILE__) ) ); 
		define('INBOUND_MAILPOET_FILE' ,  __FILE__ ); 
		define('INBOUND_MAILPOET_REMOTE_ITEM_NAME' , 'mailpoet-integration' ); 
		define('INBOUND_MAILPOET_URLPATH', plugins_url( ' ', __FILE__ ) ); 
		define('INBOUND_MAILPOET_PATH', WP_PLUGIN_DIR.'/'.plugin_basename( dirname(__FILE__) ).'/' ); 
	}
	
	
	/**
	* Load Hooks & Filters 
	*/
	public static function load_hooks() {
		/* Add lead to mailpoet list when added to lead list */
		add_action( 'add_lead_to_lead_list' , array( __CLASS__ , 'add_to_list' ) , 10 , 2);
		
		/* only load the rest of the hooks if is_admin() */
		if (!is_admin()) {
			return;
		}
		
		/* Setup Automatic Updating & Licensing */
		add_action('admin_init', array( __CLASS__ , 'license_setup') );
		
		/* Listens for command to begin synchronization process */
		add_action( 'admin_init', array( __CLASS__ , 'build_sync_queue' ) , 1 );
		
		/* Process leads when synch queue is populated */
		add_action( 'admin_init', array( __CLASS__ , 'process_queue' ) , 10);
		
		/* Add admin notice to sync leads with mailpoet - nags until run */
		add_action( 'admin_notices', array( __CLASS__ , 'display_sync_notice' ) );
		
		/* Add admin notice to display progress of synchronization */
		add_action( 'admin_notices', array( __CLASS__ , 'display_sync_progress' ) );
			
	}
	
	/**
	*  Adds a lead list to a mailchimp list
	*  
	*  @param INT $lead_id posttype ID of lead
	*  @param MIXED $list_id INT or ARRAY of lead lists the lead is being sorted into
	*/
	public static function add_to_list( $lead_id , $list_id ) {
		global $Inbound_Leads;
		
		/* Get lead data */
		$meta = get_post_meta( $lead_id );
		
		/* standardize list id(s) into array if not already in array */
		$lead_lists = (!is_array($list_id)) ? array( $list_id ) : $list_id;
		
		/* Build acceptable user data array */
		$user_data['email'] = (isset( $meta['wpleads_email_address'][0] )) ?  $meta['wpleads_email_address'][0] : '' ;
		$user_data['firstname'] = (isset( $meta['wpleads_first_name'][0] )) ?  $meta['wpleads_first_name'][0] : '' ;
		$user_data['lastname'] = (isset( $meta['wpleads_last_name'][0] )) ?  $meta['wpleads_last_name'][0] : '' ;
		
		
		/* Open WYSIJA lists & user classes */		
		$Lists = WYSIJA::get('list', 'model');
		$Users = WYSIJA::get('user', 'helper');
		$Users_Model = WYSIJA::get('user', 'model');
		$Users_Model->getFormat=ARRAY_A;
		
		/* Get array of all currently enabled MailPoet lists */
		$mailpoet_lists = $Lists->get(array('name', 'list_id', 'is_public'), array('is_enabled' => 1));      

		
		/* loop through each lead list get corresponding mailpoet list ids */
		foreach ($lead_lists as $id) {

			/* Get lead list name from lead id & set in array */
			$lead_list = $Inbound_Leads->get_lead_list_by( 'id' , $id );

			/* Search for mailpoet list by name */
			$mailpoet_list_id = Inbound_MailPoet::search_for_list_by_name( $mailpoet_lists , $lead_list['name'] );
			
			/* Create a new list if not exist */
			if ( !$mailpoet_list_id ) {
				$mailpoet_list_ids[] = $Lists->insert(array('is_enabled' => 1, 'name' => $lead_list['name'] , 'description' => $lead_list['description'] ));
			} else {
				$mailpoet_list_ids[] = $mailpoet_list_id;
			}
		}

		
		/* Add lead to list */
		$data_subscriber = array(
			'user' => $user_data,
			'user_list' => array( 'list_ids' => $mailpoet_list_ids )
		);	

		$Users->addSubscriber($data_subscriber);
		//echo 'here'; exit;
	}

	/**
	*  Searches for a list id given a name and an array of mailpoet list data
	*  @param ARRAY $mailpoet_lists generated by $MailPoet = WYSIJA::get('list', 'model')->get(array('name', 'list_id', 'is_public'), array('is_enabled' => 1));   
	*  @param STRING $name Name/label of list we want the list id for.
	*  
	*  @returns INT mailpoet list id or false
	*/
	public static function search_for_list_by_name( $mailpoet_lists , $name ) {
		
		foreach ($mailpoet_lists as $list) {
			if ( $list['name'] == $name ) {
				return $list['list_id'];
			}
		}
		
		return false;
	}
	
	/**
	* Setups Software Update API 
	*/
	public static function license_setup() {

		/*PREPARE THIS EXTENSION FOR LICESNING*/
		if ( class_exists( 'Inbound_License' ) ) {
			$license = new Inbound_License( INBOUND_MAILPOET_FILE , INBOUND_MAILPOET_LABEL , INBOUND_MAILPOET_SLUG , INBOUND_MAILPOET_CURRENT_VERSION  , INBOUND_MAILPOET_REMOTE_ITEM_NAME ) ;
		}
	}
	
	/**
	* Listens for a build queue command
	*/
	public static function build_sync_queue() {
		global $table_prefix;

		if (  !isset($_GET['inbound_action']) || $_GET['inbound_action'] != 'sync_leads_with_mailpoet' ) {
			return;
		}
			
		$lead_queue = array();

		$leads = get_posts( array(
			'post_type' => 'wp-lead',
			'posts_per_page' => -1,
			'post_status' => 'publish'
		));
		
		foreach ($leads as $lead) {
			$lead_queue[][ 'ID' ] = $lead->ID;
		}
		
		set_transient( 'inbound_sync_leads_mailpoet' , $lead_queue , '' , false ); 
		
		wp_redirect(admin_url());
		exit;
		
	}
	
	/**
	*  Adds notice to sync leads db with mailpoet if have not done this yet.
	*/
	public static function display_sync_notice() {
		global $current_user ;

		if ( get_option( 'inbound_ignore_mailpoet_notice' ) || get_transient('inbound_sync_leads_mailpoet')) {
			return;
		}
		?>
		<div class="update-nag">
			<strong><?php _e('Inbound MailPoet Extension: Notice' , 'inbound-pro' ); ?></strong><br>
				<p><?php _e( 'Thank you for installing the MailPoet extension. Please synchronize your current Leads lists with MailPoet lists:', 'inbound-pro' ); ?> 
				<a href='?inbound_action=sync_leads_with_mailpoet'> <?php _e('Sync now!' , 'inbound-pro' ); ?></a>
				<!--<br>				<a href='?inbound_action=dismiss_inbound_mailpoet_notice'><?php _e('Dismiss this notice' , 'inbound-pro' ); ?></a>-->
			</p>
		</div>
		<?php
	}
	
	/**
	* Listens for a dismiss notice command
	*/
	public static function dismiss_notice() {
		if (  !isset($_GET['inbound_action']) || $_GET['inbound_action'] != 'dismiss_inbound_mailpoet_notice' ) {
			return;
		}
			
		delete_option( 'inbound_ignore_mailpoet_notice');
		add_option( 'inbound_ignore_mailpoet_notice' , true , '' , 'no' );	
	}
	
	
	/**
	*  Adds notice to display sync progress
	*/
	public static function display_sync_progress() {
		$queue = get_transient('inbound_sync_leads_mailpoet');
		
		/* Only show notice if queue is populated */
		if ( !$queue ) {
			return;
		}

		$queue_count = count($queue);
		$remaining = number_format( $queue_count / 300 , 0 );
		
		if (!$remaining) {
			$remaining = 1;
		}
		
		?>
		<div class="update-nag">
			<strong><?php _e('Inbound MailPoet Extension: Notice' , 'inbound-pro' ); ?></strong><br>
			<p><?php echo sprintf( __('<b>%s leads</b> remain in processing queue. Please refresh wp-admin <b>%s</b> more times to complete the synchronization process.' , 'inbound-pro' ) , $queue_count , $remaining ); ?> 
			</p>
		</div>
		<?php
	}
	
	/**
	* Processes leads in queue, discovering their lists and synching them with mailpoet lists
	*/
	public static function process_queue() {
		
		$leads = get_transient('inbound_sync_leads_mailpoet');

		/* only process queue if queue is populated */
		if (!$leads) {
			return;
		}
		
		if (!method_exists( 'Inbound_Leads' , 'get_lead_lists_by_lead_id') ) {
			_e( 'Cannot process. MailPoet integration extension requires the latest version(s) of Inbound now software' , 'inbound-pro' );
			return;
		}
		
		$i = 0;
		//echo 'count: '. count($leads);
		//echo '<br>';
		foreach ($leads as $key => $lead) {
			//echo $lead['ID'];
			//echo '<br>';
			/* only process 300 a page load */
			if ($i>300) {
				break;
			}
			/* Remove lead from queue  */
			unset($leads[ $key ]);
			
			/* Get array of lists belonging to lead */
			$lead_lists = Inbound_Leads::get_lead_lists_by_lead_id( $lead['ID'] );
			
			/* Set list ids as values */
			$lead_lists = array_flip($lead_lists);
			
			
			/* lead has no lists then continue to next lead in loop */
			if (!$lead_lists) {				
				continue;
			}
			
			/* Add lead to syched mailpoet list */			
			self::add_to_list( $lead['ID'] , $lead_lists );
				
			$i++;
		}
		
		//echo 'count: '. count($leads);
		//echo '<br>';
		
		/* Disable synch nag globally */
		if (!$leads) {	
			delete_transient( 'inbound_sync_leads_mailpoet');
			update_option( 'inbound_ignore_mailpoet_notice' , true  ); exit;
		} else {	
			set_transient( 'inbound_sync_leads_mailpoet' , $leads  ); 
			//echo count(get_transient('inbound_sync_leads_mailpoet'));
		}
	}


	/**
	 * Helper log function for debugging
	 *
	 * @since 1.2.2
	 */
	static function log( $message ) {
		if ( WP_DEBUG === true ) {
			if ( is_array( $message ) || is_object( $message ) ) {
				error_log( print_r( $message, true ) );
			} else {
				error_log( $message );
			}
		}
	}

}


$GLOBALS['Inbound_Mailpoet'] = new Inbound_Mailpoet();




}