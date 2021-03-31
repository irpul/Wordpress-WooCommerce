<?php
/*
Plugin Name: پرداخت آنلاین ایرپول- ووکامرس
Plugin URI: http://irpul.ir
Description: پرداخت آنلاین فروشگاه ساز ووکامرس با سامانه ایرپول . طراحی شده توسط <a target="_blank" href="https://irpul.ir">درگاه پرداخت ایرپول</a>
Version: 2.1
Author: irpul
Author URI: http://irpul.ir
Copyright: 2021 irpul.ir
 */

add_action('plugins_loaded', 'woocommerce_irpul_init', 0);
function woocommerce_irpul_init() {

    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
	if( isset($_GET['msg']) && $_GET['msg']!=''){
		add_action('the_content', 'irpul_showMessage');
	}

    function irpul_showMessage($content){
            return '<div class="box '.htmlentities($_GET['type']).'-box">'.base64_decode($_GET['msg']).'</div>'.$content;
    }
	
    class WC_irpul extends WC_Payment_Gateway {
		protected $msg = array();
        public function __construct(){
            // Go wild in here
            $this->id = 'irpul';
            $this->method_title = __('سامانه ایرپول', 'irpul');
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            //$this->merchant_id = $this->settings['merchant_id'];
	        $this->token = $this->settings['token'];
			$this->zegersot_p = $this->settings['zegersot_p'];
            $this->redirect_page_id = $this->settings['redirect_page_id'];
            $this->msg['message'] = "";
            $this->msg['class'] = "";
			add_action( 'woocommerce_api_wc_irpul', array( $this, 'check_irpul_response' ) );
            add_action('valid-irpul-request', array($this, 'successful_request'));
			
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            else{
                add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
            }
			
            add_action('woocommerce_receipt_irpul', array(&$this, 'receipt_page'));
			add_action('woocommerce_thankyou_irpul',array($this, 'thankyou_page'));
        }

        function init_form_fields(){

            $this->form_fields = array(
                'enabled'           => array('title' => __('فعال سازی/غیر فعال سازی', 'irpul'), 'type' => 'checkbox','label' => __('فعال سازی سامانه پرداخت الکترونیک ایرپول', 'irpul'),'default' => 'no'),
                'title'             => array('title' => __('عنوان:', 'irpul'), 'type' => 'text','description' => __('عنوانی که کاربر در هنگام پرداخت مشاهده می کند', 'irpul'),'default' => __('پرداخت اینترنتی ایرپول', 'irpul')),
                'description'       => array('title' => __('توضیحات:', 'irpul'), 'type' => 'textarea','description' => __('توضیحات قابل نمایش به کاربر در هنگام انتخاب ایرپول', 'irpul'), 'default' => __('پرداخت از طریق سامانه ایرپول با کارت های عضو شتاب', 'irpul')),
                //'merchant_id'       => array('title' => __('شناسه درگاه', 'irpul'), 'type' => 'text', 'description' => __('شناسه درگاه ثبت شده شما در سایت ایرپول')),
				'token'             => array('title' => __('توکن درگاه ایرپول', 'irpul'), 'type' => 'text', 'description' => __('توکن درگاه ایرپول')),
				'zegersot_p'        => array('title' => __('واحد پولی'), 'type' => 'select','options' => array('rial' => 'ریال','toman' => 'تومان'),'description' => "نیازمند افزونه ریال و تومان هست"),
                'redirect_page_id'  => array('title' => __('صفحه بازگشت'), 'type' => 'select','options' => $this->get_pages('انتخاب برگه'),'description' => "ادرس بازگشت از پرداخت در هنگام پرداخت")
            );
        }

        public function admin_options(){
            echo '<h3>'.__('سامانه پرداخت الکترونیک ایرپول', 'irpul').'</h3>';
            echo '<p>'.__('ایرپول اینترنتی').'</p>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';

        }

        function payment_fields(){
            if($this->description) echo wpautop(wptexturize($this->description));
        }

        function receipt_page($order){
            echo '<p>'.__('با تشکر از سفارش شما. در حال انتقال به ایرپول...', 'irpul').'</p>';
            echo $this->generate_irpul_form($order);
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
					$trans_id 	= $ir_output['tran_id'];
					$order_id 	= $ir_output['order_id'];
					$amount 	= $ir_output['amount'];
					$refcode	= $ir_output['refcode'];
					$status 	= $ir_output['status'];

					$order = new WC_Order($order_id);
					if($status == 'paid'){
						if($order_id != ''){
							if($order->status !='completed'){

								if($this->zegersot_p == 'toman'){
									$amount = round($order->order_total*10);
								}
								else{
									$amount = round($order->order_total);
								}

								$token = $this->token;
								$result = $this->get($token,$trans_id,$amount);

								if( isset($result['http_code']) ){
									$data =  json_decode($result['data'],true);

									if( isset($data['code']) && $data['code'] === 1){
										$this->msg['message'] = "پرداخت شما با موفقیت انجام شد | مبلغ پرداختی: $amount | شماره تراکنش: $trans_id | شماره سفارش: $order_id | رسید تراکنش: $refcode  <br/> ";
										$this->msg['class'] = 'success';
										$order->payment_complete();
										$order->add_order_note('پرداخت انجام شد<br/>شناسه تراکنش: '.$trans_id .' AND '.$order_id );
										$order->add_order_note($this->msg['message']);
										$woocommerce->cart->empty_cart();
									}
									else{
										$this->msg['class'] = 'error';
										$this->msg['message'] = 'Error Code: '.$data['code'] . '\r\n ' . $data['status'];
									}
								}else{
									$this->msg['class'] = 'error';
									$this->msg['message'] = "پاسخی از سرویس دهنده دریافت نشد. لطفا دوباره تلاش نمائید";
								}

							}else{
								$this->msg['class'] = 'error';
								$this->msg['message'] = "قبلا اين سفارش به ثبت رسيده يا صفارشي موجود نيست!";
							}
						}
					}
					else{
						$this->msg['class'] = 'error';
						$this->msg['message'] = "پرداخت با موفقيت انجام نشد";
					}
				}
				else{
					$this->msg['class'] = 'error';
					$this->msg['message'] = "توکن ایرپول صحیح نیست";
				}
			}
			else{
				$this->msg['class'] = 'error';
				$this->msg['message'] = "توکن ایرپول موجود نیست";
			}
			
			$redirect_url = ($this->redirect_page_id=="" || $this->redirect_page_id==0)?get_site_url() . "/":get_permalink($this->redirect_page_id);
			$redirect_url = add_query_arg( array('msg'=> base64_encode($this->msg['message']), 'type'=>$this->msg['class']), $redirect_url );
            wp_redirect( $redirect_url );
            exit;
        }

        function irpul_showMessage($content){
            return '<div class="box '.$this->msg['class'].'-box">'.$this->msg['message'].'</div>'.$content;
        }

        public function generate_irpul_form($order_id){
            global $woocommerce;
            $order 			= new WC_Order($order_id);
            $redirect_url 	= ($this->redirect_page_id=="" || $this->redirect_page_id==0)?get_site_url() . "/":get_permalink($this->redirect_page_id);
			$redirect_url 	= add_query_arg( 'wc-api', get_class( $this ), $redirect_url );
			unset( $woocommerce->session->zegersot );
			unset( $woocommerce->session->zegersot_id );
			$woocommerce->session->zegersot = $order_id;

			$redirect 	= urlencode($redirect_url);
			if($this->zegersot_p=='toman'){
				$amount = round($order->order_total*10);
			}
			else{
				$amount = round($order->order_total);
			}
	
			$products_array = $order->get_items();
			$products_name 	= '';
			$i 				= 0;
			$count 			= count($products_array);	
			foreach ( $products_array as $product) {
				$products_name .= 'تعداد '. $product['qty'] . ' عدد ' . $product['name'];
				if ($i!=$count-1) {	
					$products_name .= ' | ';
				}
				$i++;
			}

	        //$webgate_id		= $this->merchant_id;

			$parameters = array(
				//'plugin'		=> 'WooCcommerce',
				//'webgate_id' 	=> $webgate_id,
				'method' 	    => 'payment',
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
				'test_mode' 	=> false,
			);

	        $token		= $this->token;
	        $result 	=  $this->post_data('https://irpul.ir/ws.php', $parameters, $token );

	        if( isset($result['http_code']) ){
		        $data =  json_decode($result['data'],true);

		        if( isset($data['code']) && $data['code'] === 1){
			        $woocommerce->session->zegersot_id = $result;
			        header("Location: ". $data['url']);
		        }
		        else{
			       echo	'Error Code: '.$data['code'] . '\r\n ' . $data['status'];
		        }
	        }else{
		        echo 'پاسخی از سرویس دهنده دریافت نشد. لطفا دوباره تلاش نمائید';
	        }
        }

	    function post_data($url,$params,$token) {//used in mellat.php gateway for send to sng.co.ir
		    ini_set('default_socket_timeout', 15);// برای جلوگیری از تایم اوت شدن در صورت عدم پاسخ وب سرویس

		    $headers = array(
			    "Authorization: token= {$token}",
			    'Content-type: application/json'
		    );

		    $handle = curl_init($url);
		    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
		    curl_setopt($handle, CURLOPT_TIMEOUT, 40);

		    curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($params) );
		    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers );

		    $response = curl_exec($handle);
		    //error_log('curl response1 : '. print_r($response,true));

		    $msg='';
		    $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));

		    $status= true;

		    if ($response === false) {
			    $curl_errno = curl_errno($handle);
			    $curl_error = curl_error($handle);
			    $msg .= "Curl error $curl_errno: $curl_error";
			    $status = false;
		    }

		    curl_close($handle);//dont move uppder than curl_errno

		    if( $http_code == 200 ){
			    $msg .= "Request was successfull";
		    }
		    else{
			    $status = false;//غیر از 200 هر پاسخی دریافت شود مقدار استاتیوس فالس می شود
			    if ($http_code == 400) {
				    $status = true;// در انیاک خطای 400 برای کدهای خطا استفاده میشه
			    }
			    elseif ($http_code == 401) {
				    $msg .= "Invalid access token provided";
			    }
			    elseif ($http_code == 502) {
				    $msg .= "Bad Gateway";
			    }
			    elseif ($http_code >= 500) {// do not wat to DDOS server if something goes wrong
				    sleep(2);
			    }
		    }

		    $res['http_code'] 	= $http_code;
		    $res['status'] 		= $status;
		    $res['msg'] 		= $msg;
		    $res['data'] 		= $response;

		    if(!$status){
			    //error_log(print_r($res,true));
		    }
		    return $res;
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
		
		private function get($token,$trans_id,$amount){
			$parameters = array(
				'method' 	    => 'verify',
				'trans_id' 	    => $trans_id,
				'amount'	 	=> $amount,
			);

			$result 	=  $this->post_data('https://irpul.ir/ws.php', $parameters, $token );

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
