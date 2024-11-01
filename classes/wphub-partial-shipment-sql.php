<?php

defined( 'ABSPATH' ) || exit;

if(!class_exists('WpHub_Partial_Shipment_Sql')){

    class WpHub_Partial_Shipment_Sql{

	    function __construct(){

	    }

        function create(){
	        global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();
	        include_once ABSPATH . 'wp-admin/includes/upgrade.php';
	        $sql = "CREATE TABLE IF NOT EXISTS ".$wpdb->prefix."partial_shipment(
		    `id` bigint(20) NOT NULL AUTO_INCREMENT,
		    `order_id` bigint(20) NOT NULL,
		    `shipment_id` bigint(20) NOT NULL,
		    `shipment_url` varchar(300) NOT NULL,
		    `shipment_num` varchar(100) NOT NULL,
		    `shipment_date` varchar(30) NOT NULL,
		    PRIMARY KEY (`id`)
		   ) $charset_collate;";
	        dbDelta($sql);

            $sql2 = "CREATE TABLE IF NOT EXISTS ".$wpdb->prefix."partial_shipment_items(
		    `id` bigint(20) NOT NULL AUTO_INCREMENT,
		    `shipment_id` bigint(20) NOT NULL,
			`shipment_primary_id` bigint(20) NOT NULL,
		    `item_id` varchar(30) NOT NULL,
		    `item_qty` int(20) NOT NULL, 
		    PRIMARY KEY (`id`)
		   ) $charset_collate;";  
            dbDelta($sql2);  
        }
    }
}