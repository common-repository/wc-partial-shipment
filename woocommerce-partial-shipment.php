<?php
/**
* Plugin Name: Woocommerce Partial Shipment
* Plugin URI: https://wpexpertshub.com/
* Description: Add ability to partially ship an order.
* Author: WpExperts Hub
* Version: 3.2
* Author URI: https://wpexpertshub.com/
* Text Domain: wxp-partial-shipment
* License: GPLv3
*Requires Plugins: woocommerce
* Requires at least: 6.0
* Tested up to: 6.6
* Requires PHP: 7.4
* Stable tag: 3.2
* WC requires at least: 8.0
* WC tested up to: 9.2
**/


defined( 'ABSPATH' ) || exit;

class WXP_Partial_Shipment{

	protected static $_instance = null;
    protected $wc_partial_labels = array();
	protected $wc_partial_shipment_settings = array();
	public static function instance(){

		if(is_null(self::$_instance)){
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	function __construct(){
		if(!defined('WXP_PARTIAL_SHIP_VER')){
			define('WXP_PARTIAL_SHIP_VER',3.2);
		}
		if(!defined('WXP_PARTIAL_SHIP_DIR')){
			define('WXP_PARTIAL_SHIP_DIR',__DIR__);
		}
		add_action('before_woocommerce_init',array($this,'hpos_compatibility'));
		add_action('init',array($this,'init_autoload'));
		add_action('init',array($this,'autoload_classes'));
		add_action('plugins_loaded',array($this,'load_textdomain'));
		add_action('init',array($this,'load_settings'));
		add_action('init',array($this,'wxp_partial_complete_register_status'),999);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__),array($this,'wxp_partial_action_links'),10,1);
		register_activation_hook(__FILE__,array($this,'partial_shipment_active'));

		add_action('woocommerce_admin_order_item_headers',array($this,'wxp_order_item_headers'),10,1);
		add_action('woocommerce_admin_order_item_values',array($this,'wxp_order_item_values'),10,3);
		add_action('admin_enqueue_scripts',array($this,'wxp_admin_head'),999);
		add_action('wp_enqueue_scripts',array($this,'wxp_front'));
		add_action('woocommerce_order_item_add_action_buttons',array($this,'wxp_order_shipment_button'),10,1);

		add_action('wp_ajax_wxp_order_shipment',array($this,'wxp_order_shipment'));
		add_action('wp_ajax_wxp_order_item_shipment',array($this,'wxp_order_item_shipment'));
		add_action('wp_ajax_wxp_order_set_shipped',array($this,'wxp_order_set_shipped'));

		add_action('woocommerce_order_item_meta_end',array($this,'wxp_order_item_icons'),999,4);
		add_filter('wc_order_statuses',array($this,'add_partial_complete_status'));

		add_filter('woocommerce_admin_order_preview_line_item_columns',array($this,'wxp_order_status_in_popup'),10,2);
		add_filter('woocommerce_admin_order_preview_line_item_column_wxp_status',array($this,'wxp_order_status_in_popup_value'),10,4);
		add_action('woocommerce_order_actions',array($this,'wxp_shipment_mail'),10,1);
		add_action('woocommerce_order_action_wxp_partial_shipment',array($this,'trigger_wxp_shipment_mail'),10,1);
		add_filter('woocommerce_email_classes',array($this,'wxp_shipment_email_class'),10,1);

		add_action('woocommerce_email_partially_shipped_order_details',array($this,'order_details'),10,4);
		add_action('wxp_order_status',array($this,'wxp_order_status_update'),10,1);
		add_action('woocommerce_order_status_completed',array($this,'wxp_order_status_switch'),10,1);

	}

	function hpos_compatibility(){
		if(class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)){
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('analytics',__FILE__,true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('new_navigation',__FILE__,true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor',__FILE__,true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks',__FILE__,true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('marketplace',__FILE__,true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('order_attribution',__FILE__,true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('hpos_fts_indexes',__FILE__,true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables',__FILE__,true);
		}
	}

	function partial_shipment_active(){
		include_once($this->plugin_path().'/classes/wphub-partial-shipment-sql.php');
		$sql = new WpHub_Partial_Shipment_Sql();
		$sql->create();
	}

    function wxp_partial_action_links($links){
        $wxp_link = array(
            '<a href="'.admin_url('admin.php?page=wc-settings&tab=wxp_partial_shipping_settings').'">'.__('Settings','wxp-partial-shipment').'</a>',
            '<a target="_blank" href="https://wpexpertshub.com/plugins/advance-partial-shipment-for-woocommerce/">'.__('Get Pro','wxp-partial-shipment').'</a>',
        );
        return array_merge($links,$wxp_link);
    }

    function plugin_path(){
		return untrailingslashit(plugin_dir_path(__FILE__));
	}

	function init_autoload(){
		spl_autoload_register(function($class){
			$class = strtolower($class);
			$class = str_replace('_','-',$class);
			//file_put_contents(ABSPATH.'r.txt',print_r($class,true).PHP_EOL,FILE_APPEND | LOCK_EX);
			if(is_file(dirname(__FILE__).'/classes/'.$class.'.php')) {
				include_once('classes/'.$class.'.php');
			}
		});
	}

	function autoload_classes(){
		$wcp = new Wxp_Partial_Shipment_Settings();
		$wcp->init();
	}

	function load_settings(){
        $this->wc_partial_labels = array(
            'shipped'=>__('Shipped','wxp-partial-shipment'),
            'not-shipped'=>__('Not Shipped','wxp-partial-shipment'),
            'partially-shipped'=>__('Partially Shipped','wxp-partial-shipment'),
        );
        $this->wc_partial_labels = apply_filters('wc_partial_labels',$this->wc_partial_labels);
		$this->wc_partial_shipment_settings = array(
			'partially_shipped_status' => get_option('partially_shipped_status')!='' ? get_option('partially_shipped_status') : 'yes',
			'partially_auto_complete' => get_option('partially_auto_complete')!='' ? get_option('partially_auto_complete') : 'yes',
			'partially_hide_status' => get_option('partially_hide_status')!='' ? get_option('partially_hide_status') : 'yes',
			'partially_enable_status_popup' => get_option('partially_enable_status_popup')!='' ? get_option('partially_enable_status_popup') : 'yes',
		);
	}

	function wxp_front(){
		wp_enqueue_style('wxp_front_style',plugins_url('',__FILE__).'/assets/css/front.css');
	}

	function wxp_admin_head(){
		$screen = get_current_screen();
		if(isset($screen->id) && in_array($screen->id,array('woocommerce_page_wc-orders','shop_order'))){
			wp_enqueue_style('fancybox',plugins_url('',__FILE__).'/assets/css/jquery.fancybox.min.css');
			wp_enqueue_style('wxp_style',plugins_url('',__FILE__).'/assets/css/admin-style.css');
			wp_enqueue_script('fancybox',plugins_url('',__FILE__).'/assets/js/jquery.fancybox.min.js',array('jquery'),WXP_PARTIAL_SHIP_VER,false);
			wp_register_script('wxp_partial_ship_script',plugins_url('',__FILE__).'/assets/js/admin-script.js',array('fancybox'),WXP_PARTIAL_SHIP_VER,true);

			$js_array = array(
				'wxp_loader' => untrailingslashit(plugins_url('/', __FILE__ )).'/images/ajax-loader.gif',
				'wxp_ajax' => admin_url('admin-ajax.php'),
				'wxp_nonce' => wp_nonce_field('wxp_partial_shipment','wxp_partial_ship',false,false),
				'wxp_title' => __('Title','wxp-partial-shipment'),
				'wxp_qty' => __('Quantity','wxp-partial-shipment'),
				'wxp_ship' => __('Shipped','wxp-partial-shipment'),
				'wxp_bulk_action' => __('Bulk Actions','wxp-partial-shipment'),
				'wxp_bulk_mark_shipped' => __('Mark as Shipped','wxp-partial-shipment'),
				'wxp_bulk_mark_not_shipped' => __('Unset Shipped','wxp-partial-shipment'),
				'wxp_update' => __('Update','wxp-partial-shipment'),
				'wxp_order_nonce' => wp_create_nonce('order-item'),
			);
			wp_localize_script('wxp_partial_ship_script','wxp_partial_ship',$js_array);
			wp_enqueue_script('wxp_partial_ship_script');
		}
	}

	function wxp_order_item_headers($order){
		echo '<th class="wxp-partital-item-head">'.__('Shipment','wxp-partial-shipment').'</th>';
		$order_id = $order->get_id();
		if($order_id){
			echo '<th class="wxp-partital-item-head">&nbsp;</th>';
		}
	}

	function wxp_order_item_values($product,$item,$item_id){

		if($product){
			$order_id = $item->get_order_id();
			$wxp_shipments = $this->get_wxp_shipment_data($order_id);
			$item_data = $item->get_data();
			$shipped = isset($wxp_shipments[$item_id]) ? $wxp_shipments[$item_id] : array('item_qty'=>0);
			if(is_a($product,'WC_Product') && !$product->is_virtual()){
				$icon = '';
				if($item_id && array_key_exists($item_id,$wxp_shipments)){
					if(isset($shipped['item_qty']) && $shipped['item_qty'] > 0 && $shipped['item_qty'] < $item_data['quantity']){
						$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . ': ' . $shipped['item_qty'] . '/' . $item_data['quantity'] . '"><span class="wxp-partial-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . ' - ' . $shipped['item_qty'] . '</span></a>';
					}
					elseif(isset( $shipped['item_qty']) && $shipped['item_qty'] > 0 && $shipped['item_qty'] == $item_data['quantity']){
						$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ': ' . $shipped['item_qty'] . '/' . $item_data['quantity'] . '"><span class="wxp-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ' - ' . $shipped['item_qty'] . '</span></a>';
					}
					elseif(isset($shipped['item_qty']) && $shipped['item_qty']<1){
						$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
					}
					elseif($item_id && ! array_key_exists($item_id,$wxp_shipments)){
						$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
					}
				}
				elseif($item_id && !array_key_exists($item_id,$wxp_shipments)){
					$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
				}
				echo '<td class="wxp-partital-line-item"><a href="javascript:void(0);" data-item-id="' . $item_id . '" data-order-id="' . $order_id . '" title="' . __( 'Manage Shipment', 'wxp-partial-shipment' ) . '" class="wxp-icons icon-wxp-set-shipping"></a></td>';
				echo '<td class="wxp-partital-item-icon" width="1%">' . $icon . '</td>';
			}
			elseif(is_a($product,'WC_Product') && $product->is_virtual()){
				$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ': ' . $item_data['quantity'] . '/' . $item_data['quantity'] . '"><span class="wxp-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
				echo '<td class="wxp-partital-line-item"><a href="javascript:void(0);" data-item-id="' . $item_id . '" data-order-id="' . $order_id . '" title="' . __( 'Manage Shipment', 'wxp-partial-shipment' ) . '" class="wxp-icons icon-wxp-set-shipping"></a></td>';
				echo '<td class="wxp-partital-item-icon" width="1%">' . $icon . '</td>';
			}
		}
		else
		{
			echo '<td></td>';
			echo '<td></td>';
		}
	}

	function wxp_order_shipment_button($order){
		echo '<button type="button" data-order-id="'.$order->get_id().'" class="button wxp-order-shipment">'.__('Shipment','wxp-partial-shipment').'</button>';
	}

	function wxp_order_shipment(){
		$valid = false;
		$init = false;
		$products = array();

		if(isset($_POST['order_id']) && $_POST['order_id']){
			$order_id  = $_POST['order_id'];
            $order = wc_get_order($order_id);
			if(is_a($order,'WC_Order')){
				$wxp_shipment = $this->get_wxp_shipment_data($order_id);
				$init = is_array($wxp_shipment) && !empty($wxp_shipment) ? true : false;
				$items = $order->get_items();
				if(is_array($items) && !empty($items)){
					$valid = true;
					foreach($items as $item){
						$item_id = $item->get_id();
						$shipped = isset($wxp_shipment[$item_id]) ? $wxp_shipment[$item_id] : array('item_qty'=>0);
						$product = $item->get_product();
						$products[] = array(
							'id' => $item->get_id(),
							'name' => $item->get_name(),
							'virtual' => is_a($product,'WC_Product') ? $product->is_virtual() : false,
							'qty' => $item->get_quantity(),
							'shipped' => $shipped['item_qty'],
							'order_id' => $order_id
						);
					}
				}
			}
		}
		echo json_encode(array('order_id'=>$_POST['order_id'],'valid'=>$valid,'products'=>$products,'init'=>$init));
		exit();
	}

	function wxp_order_item_shipment(){

		$products = array();
		$valid = false;
		$item_id = isset($_POST['item_id']) ? $_POST['item_id'] : 0;
		$order_id = isset($_POST['order_id']) ? $_POST['order_id'] : 0;
		if($order_id && $item_id){
			$wxp_shipment = $this->get_wxp_shipment_data($order_id);
			$item  = WC_Order_Factory::get_order_item($item_id);
			if(is_a($item,'WC_Order_Item_Product')){
				$valid = true;
				$item_data = $item->get_data();
				$products[] = array(
					'id' => $item_data['id'],
					'item_id' => $item_data['id'],
					'name' => $item_data['name'],
					'qty' => $item_data['quantity'],
					'shipped' => is_array($wxp_shipment) && array_key_exists($item_id,$wxp_shipment) ? $wxp_shipment[$item_id]['item_qty'] : 0,
					'order_id' => $item['order_id']
				);
			}
		}

		echo json_encode(array('order_id'=>$order_id,'item_id'=>$item_id,'valid'=>$valid,'products'=>$products));
		exit();
	}

	function wxp_order_set_shipped(){

		$order_id = isset($_POST['order_id']) ? $_POST['order_id'] : 0;
		$order = wc_get_order($order_id);
		$status_key = '';

		$wxp_shipment = $this->get_wxp_shipment_data($order_id);
		if(isset($_POST['order_id']) && $_POST['order_id']){
			global $wpdb;
			$shipment_id = $this->get_shipment_id($_POST['order_id']);
			if(!$shipment_id){
				$data = array(
					'order_id' =>$order_id,
					'shipment_id' =>1,
					'shipment_url'=>'',
					'shipment_num'=>'',
					'shipment_date'=>current_time('timestamp',0),
				);
				$wpdb->insert($wpdb->prefix."partial_shipment",$data,array('%d','%d','%s','%s','%s'));
				$shipment_id = $wpdb->insert_id;
			}

			if($shipment_id){
				foreach($_POST['shipped'] as $shipped_item_key=>$shipped_item){
					if($shipped_item['shipped']>0){
						$item_id = isset($shipped_item['item_id']) ? $shipped_item['item_id'] : 0;
						$qty_shipped = isset($shipped_item['shipped']) && isset($shipped_item['type']) && $shipped_item['type']=='shipped' ? $shipped_item['shipped'] : 0;
						if(array_key_exists($item_id,$wxp_shipment)){
							$wpdb->update($wpdb->prefix."partial_shipment_items",
								array('item_qty'=>$qty_shipped),
								array('shipment_id'=>$shipment_id,'item_id'=>$item_id),
								array('%d'),
								array('%d','%d')
							);
						}
						elseif($qty_shipped>0)
						{
							$data = array(
								'shipment_id' => $shipment_id,
								'shipment_primary_id' => 1,
								'item_id'=>$item_id,
								'item_qty'=>isset($shipped_item['shipped']) ? $shipped_item['shipped'] : 0,
							);
							$wpdb->insert($wpdb->prefix."partial_shipment_items",$data,array('%d','%d','%d','%d'));
						}
					}
				}
			}

			if(is_a($order,'WC_Order')){
				do_action('wxp_order_status',$order_id);
				$order->update_meta_data('_init_wxp_shipment',1);
				$order->save();
				$order = wc_get_order($order_id);
				$status_key = $order->get_status();
				$status_key = wc_is_order_status('wc-' .$status_key) ? 'wc-' . $status_key : $status_key;
			}
		}

		echo json_encode(array('order_id'=>$order_id,'status'=>$status_key));
		exit();
	}

	function get_shipment_id($order_id){
		global $wpdb;
		$qry = "SELECT id as ship_id FROM ".$wpdb->prefix."partial_shipment WHERE order_id=".$order_id;
		$shipment_id = $wpdb->get_var($qry);
		return $shipment_id;
	}

	function get_wxp_shipment_data($order_id){
		global $wpdb;
		$shipment = array();
		$qry = "SELECT id as ship_id FROM ".$wpdb->prefix."partial_shipment WHERE order_id=".$order_id;
		$shipment_id = $wpdb->get_var($qry);
		if($shipment_id>0){
			$qry = "SELECT * FROM ".$wpdb->prefix."partial_shipment_items WHERE shipment_id=".$shipment_id;
			$shipment_items = $wpdb->get_results($qry,ARRAY_A);
			if(is_array($shipment_items) && !empty($shipment_items)){
				foreach($shipment_items as $item_key=>$item){
					if(isset($item['item_id'])){
						$shipment[$item['item_id']]=$item;
					}
				}
			}
		}
		return $shipment;
	}

	// Front End Status
	function wxp_order_item_icons($item_id,$item,$order,$bol = false){

		$icon = '';
		$show = true;
		$order_id = is_a($item,'WC_Order_Item_Product') ? $item->get_order_id() : 0;
		if(is_a($order,'WC_Order')){
			$product = is_callable(array($item,'get_product')) ? $item->get_product() :  null;
			$init = $order->get_meta('_init_wxp_shipment');
			if(isset($this->wc_partial_shipment_settings['partially_hide_status']) && $this->wc_partial_shipment_settings['partially_hide_status']=='yes' && $init!='1'){
				$show = false;
			}
			if($show){
				$wxp_shipments = $this->get_wxp_shipment_data($order_id);
				$item_data = $item->get_data();
				$shipped = isset($wxp_shipments[$item_id]) ? $wxp_shipments[$item_id] : array('item_qty'=>0);
				if(is_a($product,'WC_Product') && !$product->is_virtual()){
					if($item_id && array_key_exists($item_id,$wxp_shipments)){
						if(isset($shipped['item_qty']) && $shipped['item_qty'] > 0 && $shipped['item_qty'] < $item_data['quantity']){
							$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . ': ' . $shipped['item_qty'] . '/' . $item_data['quantity'] . '"><span class="wxp-partial-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . ' - ' . $shipped['item_qty'] . '</span></a>';
						}
						elseif(isset( $shipped['item_qty']) && $shipped['item_qty'] > 0 && $shipped['item_qty'] == $item_data['quantity']){
							$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ': ' . $shipped['item_qty'] . '/' . $item_data['quantity'] . '"><span class="wxp-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ' - ' . $shipped['item_qty'] . '</span></a>';
						}
						elseif(isset($shipped['item_qty']) && $shipped['item_qty']<1){
							$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
						}
						elseif($item_id && ! array_key_exists($item_id,$wxp_shipments)){
							$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
						}
					}
					elseif($item_id && !array_key_exists($item_id,$wxp_shipments)){
						$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
					}
				}
				elseif(is_a($product,'WC_Product') && $product->is_virtual()){
					$icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ': ' . $item_data['quantity'] . '/' . $item_data['quantity'] . '"><span class="wxp-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
				}
			}
		}

		if(is_view_order_page()){
			echo $icon;
		}
	}

	function add_partial_complete_status($statuses){
	    if(isset($this->wc_partial_shipment_settings['partially_shipped_status'])){
            if($this->wc_partial_shipment_settings['partially_shipped_status']=='yes'){
                $statuses['wc-partial-shipped'] = __('Partially Shipped','wxp-partial-shipment');
            }
        }
		return $statuses;
	}

	function wxp_order_status_in_popup($columns,$order){
	    if(isset($this->wc_partial_shipment_settings['partially_enable_status_popup'])){
            if($this->wc_partial_shipment_settings['partially_enable_status_popup']=='yes'){
                $columns['wxp_status'] = __('Status','wxp-partial-shipment');
            }
        }
		return $columns;
	}

	function wxp_partial_complete_register_status(){
	    if(isset($this->wc_partial_shipment_settings['partially_shipped_status'])){
            if($this->wc_partial_shipment_settings['partially_shipped_status']=='yes'){
                register_post_status('wc-partial-shipped', array(
                    'label' => __('Partially Shipped','wxp-partial-shipment'),
                    'public' => true,
                    'exclude_from_search' => false,
                    'show_in_admin_all_list' => true,
                    'show_in_admin_status_list' => true,
                    'label_count' => _n_noop('Partially Shipped <span class="count">(%s)</span>', 'Partially Shipped <span class="count">(%s)</span>')
                ));
            }
        }
	}

	function wxp_order_status_in_popup_value($val,$item,$item_id,$order){
	    if(isset($this->wc_partial_shipment_settings['partially_enable_status_popup'])){
            if($this->wc_partial_shipment_settings['partially_enable_status_popup']=='yes'){

                $order_id = $item->get_order_id();
                $product = $item->get_product();
                $wxp_shipments = $this->get_wxp_shipment_data($order_id);
                $icon = '';

	            $item_data = $item->get_data();
	            $shipped = isset($wxp_shipments[$item_id]) ? $wxp_shipments[$item_id] : array('item_qty'=>0);
	            if(is_a($product,'WC_Product') && !$product->is_virtual()){
		            if($item_id && array_key_exists($item_id,$wxp_shipments)){
			            if(isset($shipped['item_qty']) && $shipped['item_qty'] > 0 && $shipped['item_qty'] < $item_data['quantity']){
				            $icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . ': ' . $shipped['item_qty'] . '/' . $item_data['quantity'] . '"><span class="wxp-partial-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['partially-shipped'], 'wxp-partial-shipment' ) . ' - ' . $shipped['item_qty'] . '</span></a>';
			            }
			            elseif(isset( $shipped['item_qty']) && $shipped['item_qty'] > 0 && $shipped['item_qty'] == $item_data['quantity']){
				            $icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ': ' . $shipped['item_qty'] . '/' . $item_data['quantity'] . '"><span class="wxp-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ' - ' . $shipped['item_qty'] . '</span></a>';
			            }
			            elseif(isset($shipped['item_qty']) && $shipped['item_qty']<1){
				            $icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
			            }
			            elseif($item_id && ! array_key_exists($item_id,$wxp_shipments)){
				            $icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
			            }
		            }
		            elseif($item_id && !array_key_exists($item_id,$wxp_shipments)){
			            $icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '"><span class="wxp-not-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['not-shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
		            }
	            }
	            elseif(is_a($product,'WC_Product') && $product->is_virtual()){
		            $icon = '<a href="javascript:void(0);" class="wxp-top" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ': ' . $item_data['quantity'] . '/' . $item_data['quantity'] . '"><span class="wxp-shipped wxp-ship-status" title="' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . '">' . __( $this->wc_partial_labels['shipped'], 'wxp-partial-shipment' ) . ' - ' . $item_data['quantity'] . '</span></a>';
	            }
                $val = $icon;

            }
        }
		return $val;
	}

	function wxp_shipment_mail($actions){
		$actions['wxp_partial_shipment'] = __('Partial shipment notification','wxp-partial-shipment');
		return $actions;
	}

	function trigger_wxp_shipment_mail($order){
		$order_id = $order->get_id();
		WC()->payment_gateways();
		WC()->shipping();
		$emails = WC()->mailer()->get_emails();
		$emails['WC_Email_Customer_Partial_shipment']->trigger($order_id);
		$order->add_order_note( __( 'Partial order details manually sent to customer.', 'wxp-partial-shipment' ), false, true );
		add_filter('redirect_post_location',array($this,'set_email_sent_message'));
	}

	function set_email_sent_message($location){
		return add_query_arg('message',11,$location);
	}

	function wxp_shipment_email_class($emails){
		$emails['WC_Email_Customer_Partial_shipment'] = include dirname(__FILE__).'/inc/class-wc-email-partial-shipment.php';
		return $emails;
	}

	function order_details($order, $sent_to_admin = false, $plain_text = false, $email = ''){

		if ( $plain_text ) {
			wc_get_template(
				'emails/plain/email-partial-order-details.php', array(
					'order'         => $order,
					'sent_to_admin' => $sent_to_admin,
					'plain_text'    => $plain_text,
					'email'         => $email,
				),
				'',
				WXP_PARTIAL_SHIP_DIR.'/'
			);
		} else {
			wc_get_template(
				'emails/email-partial-order-details.php', array(
					'order'         => $order,
					'sent_to_admin' => $sent_to_admin,
					'plain_text'    => $plain_text,
					'email'         => $email,
				),
				'',
				WXP_PARTIAL_SHIP_DIR.'/'
			);
		}
	}

	function wc_get_email_partial_order_items( $order, $args = array() ) {
		ob_start();

		$defaults = array(
			'show_sku'      => false,
			'show_image'    => false,
			'image_size'    => array( 32, 32 ),
			'plain_text'    => false,
			'sent_to_admin' => false,
		);

		$args     = wp_parse_args( $args, $defaults );
		$template = $args['plain_text'] ? 'emails/plain/email-partial-order-items.php' : 'emails/email-partial-order-items.php';

		wc_get_template( $template, apply_filters( 'woocommerce_email_order_items_args', array(
			'order'               => $order,
			'items'               => $order->get_items(),
			'show_download_links' => $order->is_download_permitted() && ! $args['sent_to_admin'],
			'show_sku'            => $args['show_sku'],
			'show_purchase_note'  => $order->is_paid() && ! $args['sent_to_admin'],
			'show_image'          => $args['show_image'],
			'image_size'          => $args['image_size'],
			'plain_text'          => $args['plain_text'],
			'sent_to_admin'       => $args['sent_to_admin'],
		) ),
			'',
			WXP_PARTIAL_SHIP_DIR.'/');

		return apply_filters( 'woocommerce_email_order_items_table', ob_get_clean(), $order );
	}

	function get_item_status($item_id,$item,$order){
		$icon = '';
		$order_id = $item->get_order_id();
		$wxp_shipments = $this->get_wxp_shipment_data($order_id);
        $product = $item->get_product();

		$item_data = $item->get_data();
		$shipped = isset($wxp_shipments[$item_id]) ? $wxp_shipments[$item_id] : array('item_qty'=>0);
		if(is_a($product,'WC_Product') && !$product->is_virtual()){
			if($item_id && array_key_exists($item_id,$wxp_shipments)){
				if(isset($shipped['item_qty']) && $shipped['item_qty'] > 0 && $shipped['item_qty'] < $item_data['quantity']){
					$icon = __('Partially Shipped','wxp-partial-shipment').' X '.$shipped['item_qty'];
				}
				elseif(isset( $shipped['item_qty']) && $shipped['item_qty'] > 0 && $shipped['item_qty'] == $item_data['quantity']){
					$icon = __('Shipped','wxp-partial-shipment').' X '.$shipped['item_qty'];
				}
				elseif(isset($shipped['item_qty']) && $shipped['item_qty']<1){
					$icon = __('Not Shipped','wxp-partial-shipment').' X '.$item_data['quantity'];
				}
				elseif($item_id && ! array_key_exists($item_id,$wxp_shipments)){
					$icon = __('Not Shipped','wxp-partial-shipment').' X '.$item_data['quantity'];
				}
			}
			elseif($item_id && !array_key_exists($item_id,$wxp_shipments)){
				$icon = __('Not Shipped','wxp-partial-shipment').' X '.$item_data['quantity'];
			}
		}
		elseif(is_a($product,'WC_Product') && $product->is_virtual()){
			$icon = __('Shipped','wxp-partial-shipment').' X '.$item_data['quantity'];
		}

		return $icon;
	}

	function wxp_order_status_update($order_id){

		$total_count = 0;
		$shipped_count = 0;
		$order = wc_get_order($order_id);
		if(is_a($order,'WC_Order')){

			$wxp_shipment = $this->get_wxp_shipment_data($order_id);
			$items = $order->get_items();
			if(is_array($items) && !empty($items)){
				foreach($items as $item){
					$product = $item->get_product();
					if(is_a($product,'WC_Product') && !$product->is_virtual()){
						$total_count = $total_count+$item->get_quantity();
					}
				}
			}
			if(is_array($wxp_shipment) && !empty($wxp_shipment)){
				foreach($wxp_shipment as $shipped_item){
					if(isset($shipped_item['item_qty'])){
						$shipped_count = $shipped_count+$shipped_item['item_qty'];
					}
				}
			}

			if($total_count>0 && $shipped_count==$total_count && wc_is_order_status('wc-completed')){
				if($this->wc_partial_shipment_settings['partially_auto_complete']=='yes'){
					$order->update_status('completed',__('Order Completed by Woocommerce Partial Shipment.','wxp-partial-shipment'));
					$order->save();
				}
			}
			elseif($total_count>0 && $shipped_count<1 && wc_is_order_status('wc-processing')){
				$order->update_status('processing',__('Order Processed by Woocommerce Partial Shipment.','wxp-partial-shipment'));
				$order->save();
			}
			elseif($total_count>0 && $shipped_count>0 && $shipped_count<$total_count && wc_is_order_status('wc-partial-shipped')){
				if($this->wc_partial_shipment_settings['partially_shipped_status']=='yes'){
					$order->update_status('partial-shipped',__('Order Partially Shipped by Woocommerce Partial Shipment.','wxp-partial-shipment'));
					$order->save();
				}
			}
		}
	}

	function load_textdomain(){
		load_plugin_textdomain('wxp-partial-shipment',false,dirname(plugin_basename(__FILE__)).'/lang/');
	}

	function wxp_order_status_switch($order_id){
		$order = wc_get_order($order_id);
		if(is_a($order,'WC_Order')){
			global $wpdb;
			$shipment_id = $this->get_shipment_id($order_id);
			if(!$shipment_id){
				$data = array(
					'order_id' =>$order_id,
					'shipment_id' =>1,
					'shipment_url'=>'',
					'shipment_num'=>'',
					'shipment_date'=>current_time('timestamp',0),
				);
				$wpdb->insert($wpdb->prefix."partial_shipment",$data,array('%d','%d','%s','%s','%s'));
				$shipment_id = $wpdb->insert_id;
			}

			if($shipment_id){
				$wxp_shipment = $this->get_wxp_shipment_data($order_id);
				$items = $order->get_items();
				if(is_array($items) && !empty($items)){
					foreach($items as $item_key=>$item){
						$item_id = $item->get_id();
						$item_qty = $item->get_quantity();
						if(array_key_exists($item_id,$wxp_shipment)){
							$wpdb->update($wpdb->prefix."partial_shipment_items",
								array('item_qty'=>$item_qty),
								array('shipment_id'=>$shipment_id,'item_id'=>$item_id),
								array('%d'),
								array('%d','%d')
							);
						}
						elseif($item_qty>0)
						{
							$data = array(
								'shipment_id' => $shipment_id,
								'shipment_primary_id' => 1,
								'item_id'=>$item_id,
								'item_qty'=>$item_qty,
							);
							$wpdb->insert($wpdb->prefix."partial_shipment_items",$data,array('%d','%d','%d','%d'));
						}
					}
					$order->update_meta_data('_init_wxp_shipment',1);
					$order->save();
				}
			}
		}
	}


}

function WXP_Partial_Shipment_Init(){
	return WXP_Partial_Shipment::instance();
}

if(function_exists('is_multisite') && is_multisite()){
	if(!function_exists( 'is_plugin_active_for_network')){
		require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
	}
	if(is_plugin_active_for_network('woocommerce/woocommerce.php') && !is_plugin_active_for_network('wc-partial-shipment-pro/woocommerce-partial-shipment-pro.php')){
		WXP_Partial_Shipment_Init();
	} 
}
else
{
    if(!in_array('wc-partial-shipment-pro/woocommerce-partial-shipment-pro.php',apply_filters('active_plugins',get_option('active_plugins')))){
        if(in_array('woocommerce/woocommerce.php',apply_filters('active_plugins',get_option('active_plugins')))){
            WXP_Partial_Shipment_Init();
        }
    }
}
?>