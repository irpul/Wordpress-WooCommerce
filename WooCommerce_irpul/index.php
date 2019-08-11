<?php
/*
Plugin Name: پرداخت آنلاین ایرپول- ووکامرس
Plugin URI: http://irpul.ir
Description: پرداخت آنلاین فروشگاه ساز ووکامرس با سامانه ایرپول . طراحی شده توسط <a target="_blank" href="Http://irpul.ir">سایت ایرپول انلاین</a>
Version: 2.0
Author: irpul
Author URI: http://irpul.ir
Copyright: 2014 irpul.ir
 */

add_action('plugins_loaded', 'woocommerce_irpul_init', 0);
function woocommerce_irpul_init() {

    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
	if($_GET['msg']!=''){
		add_action('the_content', 'irpul_showMessage');
	}

    function irpul_showMessage($content){
            return '<div class="box '.htmlentities($_GET['type']).'-box">'.base64_decode($_GET['msg']).'</div>'.$content;
    }
	
    class WC_irpul extends WC_Payment_Gateway {
		protected $msg = array();
        public function __construct(){
            // Go wild in here
            $this -> id = 'irpul';
            $this -> method_title = __('سامانه ایرپول', 'irpul');
            $this -> has_fields = false;
            $this -> init_form_fields();
            $this -> init_settings();
            $this -> title = $this -> settings['title'];
            $this -> description = $this -> settings['description'];
            $this -> merchant_id = $this -> settings['merchant_id'];
			$this -> zegersot_p = $this -> settings['zegersot_p'];
            $this -> redirect_page_id = $this -> settings['redirect_page_id'];
            $this -> msg['message'] = "";
            $this -> msg['class'] = "";
			add_action( 'woocommerce_api_wc_irpul', array( $this, 'check_irpul_response' ) );
            add_action('valid-irpul-request', array($this, 'successful_request'));
			
			
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
             } else {
                add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
            }
			
            add_action('woocommerce_receipt_irpul', array(&$this, 'receipt_page'));
			add_action('woocommerce_thankyou_irpul',array($this, 'thankyou_page'));
        }

        function init_form_fields(){

            $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('فعال سازی/غیر فعال سازی', 'irpul'),
                    'type' => 'checkbox',
                    'label' => __('فعال سازی سامانه پرداخت الکترونیک ایرپول', 'irpul'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('عنوان:', 'irpul'),
                    'type'=> 'text',
                    'description' => __('عنوانی که کاربر در هنگام پرداخت مشاهده می کند', 'irpul'),
                    'default' => __('پرداخت اینترنتی ایرپول', 'irpul')),
                'description' => array(
                    'title' => __('توضیحات:', 'irpul'),
                    'type' => 'textarea',
                    'description' => __('توضیحات قابل نمایش به کاربر در هنگام انتخاب ایرپول', 'irpul'),
                    'default' => __('پرداخت از طریق سامانه ایرپول با کارت های عضو شتاب', 'irpul')),
                'merchant_id' => array(
                    'title' => __('شناسه درگاه', 'irpul'),
                    'type' => 'text',
                    'description' => __('شناسه درگاه ثبت شده شما در سایت ایرپول')),
				'zegersot_p' => array(
                    'title' => __('واحد پولی'),
                    'type' => 'select',
                    'options' => array(
					'rial' => 'ریال',
					'toman' => 'تومان'
					),
                    'description' => "نیازمند افزونه ریال و تومان هست"),
                'redirect_page_id' => array(
                    'title' => __('صفحه بازگشت'),
                    'type' => 'select',
                    'options' => $this -> get_pages('انتخاب برگه'),
                    'description' => "ادرس بازگشت از پرداخت در هنگام پرداخت"
                )
            );
        }

        public function admin_options(){
            echo '<h3>'.__('سامانه پرداخت الکترونیک ایرپول', 'irpul').'</h3>';
            echo '<p>'.__('ایرپول اینترنتی').'</p>';
            echo '<table class="form-table">';
            $this -> generate_settings_html();
            echo '</table>';

        }

        function payment_fields(){
            if($this -> description) echo wpautop(wptexturize($this -> description));
        }

        function receipt_page($order){
            echo '<p>'.__('با تشکر از سفارش شما. در حال انتقال به ایرپول...', 'irpul').'</p>';
            echo $this -> generate_irpul_form($order);
        }

        function process_payment($order_id){
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url( true )); 
        }

       function check_irpul_response(){
			global $woocommerce;
			
			
			if( isset($_GET['irpul_token']) ){
				$irpul_token 	= $_GET['irpul_token'];
				$decrypted 		= $this->url_decrypt( $irpul_token );
				if($decrypted['status']){
					parse_str($decrypted['data'], $ir_output);
					$tran_id 	= $ir_output['tran_id'];
					$order_id 	= $ir_output['order_id'];
					$amount 	= $ir_output['amount'];
					$refcode	= $ir_output['refcode'];
					$status 	= $ir_output['status'];

					$order = new WC_Order($order_id);
					if($status == 'paid')	
					{
						if($order_id != ''){
							if($order -> status !='completed'){
								$api=$this -> merchant_id;
								if($this -> zegersot_p=='toman')	{$amount = round($order -> order_total*10);}else{$amount = round($order -> order_total);}
								$result = $this ->get($api,$tran_id,$amount);
								if($result == '1')
								{
									$this -> msg['message'] = "پرداخت شما با موفقیت انجام شد | مبلغ پرداختی: $amount | شماره تراکنش: $tran_id | شماره سفارش: $order_id | رسید تراکنش: $refcode  <br/> ";
									$this -> msg['class'] = 'success';
									$order -> payment_complete();
									$order -> add_order_note('پرداخت انجام شد<br/>کد پیگیری: '.$tran_id .' AND '.$order_id );
									$order -> add_order_note($this->msg['message']);
									$woocommerce -> cart -> empty_cart();
								}else
								{
									$this -> msg['class'] = 'error';
									$this -> msg['message'] = "پرداخت با موفقيت انجام نشد";
								}
							}else{
								$this -> msg['class'] = 'error';
								$this -> msg['message'] = "قبلا اين سفارش به ثبت رسيده يا صفارشي موجود نيست!";
							}
						}
					}
					else{
						$this -> msg['class'] = 'error';
						$this -> msg['message'] = "پرداخت با موفقيت انجام نشد";		
					}
				}
				else{
					$this -> msg['class'] = 'error';
					$this -> msg['message'] = "توکن ایرپول صحیح نیست";		
				}
			}
			else{
				$this -> msg['class'] = 'error';
				$this -> msg['message'] = "توکن ایرپول موجود نیست";		
			}
			
			$redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
			$redirect_url = add_query_arg( array('msg'=> base64_encode($this -> msg['message']), 'type'=>$this -> msg['class']), $redirect_url );
            wp_redirect( $redirect_url );
            exit;
        }

        function irpul_showMessage($content){
            return '<div class="box '.$this -> msg['class'].'-box">'.$this -> msg['message'].'</div>'.$content;
        }

        public function generate_irpul_form($order_id){
            global $woocommerce;
            $order 			= new WC_Order($order_id);
            $redirect_url 	= ($this->redirect_page_id=="" || $this ->redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
			$redirect_url 	= add_query_arg( 'wc-api', get_class( $this ), $redirect_url );
			unset( $woocommerce->session->zegersot );
			unset( $woocommerce->session->zegersot_id );
			$woocommerce->session->zegersot = $order_id;
			
			$webgate_id		= $this ->merchant_id;
			
			$redirect 	= urlencode($redirect_url);
			if($this->zegersot_p=='toman'){
				$amount = round($order->order_total*10);
			}
			else{
				$amount = round($order ->order_total);
			}
	
			$products_array = $order->get_items();
			$$products_name 	= '';
			$i 				= 0;
			$count 			= count($products_array);	
			foreach ( $products_array as $product) {
				$products_name .= 'تعداد '. $product['qty'] . ' عدد ' . $product['name'];
				if ($i!=$count-1) {	
					$products_name .= ' | ';
				}
				$i++;
			}
			
			$parameters = array
			(
				'plugin'		=> 'WooCcommerce',
				'webgate_id' 	=> $webgate_id,
				'order_id'		=> $order_id,
				'product'		=> $products_name,
				'payer_name'	=> $order->billing_first_name . ' '. $order->billing_last_name,
				'phone' 		=> $order->billing_phone,
				'mobile' 		=> '',
				'email' 		=> $order->billing_email,
				'amount' 		=> $amount,
				'callback_url' 	=> $redirect,
				'address' 		=> $order->billing_city . ' ' . $order->billing_address_1 . ' ' . $order->billing_address_2 . ' '. $order->billing_postcode ,
				'description' 	=> '',
			);
			try {
				$client = new SoapClient('https://irpul.ir/webservice.php?wsdl' , array('soap_version'=>'SOAP_1_2','cache_wsdl'=>WSDL_CACHE_NONE ,'encoding'=>'UTF-8'));
				$result = $client->Payment($parameters);
			}catch (Exception $e) { echo 'Error'. $e->getMessage();  }
			
			
			if( $result['res_code']===1  && is_numeric($result['res_code'])){ 
			$woocommerce->session->zegersot_id=$result;
			$go = $result['url'];
			header("Location: $go"); 
			}
			elseif($result['res_code']=='-1'){
				echo "<br> شناسه درگاه مشخص نشده است";
			}
			elseif($result['res_code']=='-2'){
				echo "<br> شناسه درگاه صحیح نمی باشد";
			}
			elseif($result['res_code']=='-3'){
				echo "<br> شما حساب کاربری خود را در ایرپول تایید نکرده اید";
			}
			elseif($result['res_code']=='-4'){
				echo "<br> مبلغ قابل پرداخت تعیین نشده است";
			}
			elseif($result['res_code']=='-5'){
				echo "<br> مبلغ قابل پرداخت صحیح نمی باشد";
			}
			elseif($result['res_code']=='-6'){
				echo "<br> شناسه تراکنش صحیح نمی باشد";
			}
			elseif($result['res_code']=='-7'){
				echo "<br> آدرس بازگشت مشخص نشده است";
			}
			elseif($result['res_code']=='-8'){
				echo "<br> آدرس بازگشت صحیح نمی باشد";
			}
			elseif($result['res_code']=='-9'){
				echo "<br> آدرس ایمیل وارد شده صحیح نمی باشد";
			}
			elseif($result['res_code']=='-10'){
				echo "<br> شماره تلفن وارد شده صحیح نمی باشد";
			}
			elseif($result['res_code']=='-12'){
				echo "<br> نام پلاگین (Plugin) مشخص نشده است";
			}
			elseif($result['res_code']=='-13'){
				echo "<br> نام پلاگین (Plugin) صحیح نیست";
			}
			else{
				echo "<br> خطا در اتصال. کد خطا :" . $result['res_code'];
			}
        }
		
		public function url_decrypt($string){
			$counter = 0;
			$data = str_replace(array('-','_','.'),array('+','/','='),$string);
			$mod4 = strlen($data) % 4;
			if ($mod4) {
			$data .= substr('====', $mod4);
			}
			$decrypted = base64_decode($data);
			
			$check = array('tran_id','order_id','amount','refcode','status');
			foreach($check as $str){
				str_replace($str,'',$decrypted,$count);
				if($count > 0){
					$counter++;
				}
			}
			if($counter === 5){
				return array('data'=>$decrypted , 'status'=>true);
			}else{
				return array('data'=>'' , 'status'=>false);
			}
		}
		
		private function get($api,$tran_id,$amount){
			$parameters = array
			(
				'webgate_id'	=> $api,
				'tran_id' 	=> $tran_id,
				'amount'	 	=> $amount,
			);
			try {
				$client = new SoapClient('https://irpul.ir/webservice.php?wsdl' , array('soap_version'=>'SOAP_1_2','cache_wsdl'=>WSDL_CACHE_NONE ,'encoding'=>'UTF-8'));
				$result = $client->PaymentVerification($parameters);
			}catch (Exception $e) { echo 'Error'. $e->getMessage();  }
			return $result;
		}
        function get_pages($title = false, $indent = true) {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while($has_parent) {
                        $prefix .=  ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }

    }

    function woocommerce_add_irpul_gateway($methods) {
        $methods[] = 'WC_irpul';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_irpul_gateway' );
}
?>
