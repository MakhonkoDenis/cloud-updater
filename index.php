<?php
	if( !class_exists( 'Cloud_Updater' ) ) {
		class Cloud_Updater {

			private $repository_name	= '';
			private $github_repository	= '';
			private $current_version	= '0';

			private $up_query_limit		= '1';
			private $get_alpha			= false;
			private $get_beta			= false;

			private $important_release	= '-imp';
			private $alpha_release		= '-alpha'; //To alpha update need constant CHERRY_ALPHA_UPDATE - true
			private $beta_release		= '-beta'; //To beta update need constant CHERRY_BETA_UPDATE - true

			private $hub_url			= 'https://github.com/';
			private $api_url			= 'https://api.github.com/repos/';

			private $uploads_url		= 'https://cloud.cherryframework.com/';
			private $folder_structure	= array('downloads/framework/', 'downloads/free-plugins/', 'downloads/themes/', 'downloads/plugins/');

			function __construct(){
				$user_agent = $_SERVER['HTTP_USER_AGENT'];

				if ( stristr( $user_agent, 'WordPress' ) === false ){
					$this->browser_output();
				}else{
					$this->init();
				}
			}

			private function init(){
				$this->github_repository = isset( $_GET[ 'github_repository' ] ) ? $_GET['github_repository'] : false ;

				if( $this->github_repository ){

					if( isset( $_GET[ 'up_query_limit' ] ) ){
						$this->up_query_limit = $_GET[ 'up_query_limit' ];
					}

					if( isset( $_GET[ 'get_alpha' ] ) ){
						$this->get_alpha = $_GET[ 'get_alpha' ];
					}

					if( isset( $_GET[ 'get_beta' ] ) ){
						$this->get_beta = $_GET[ 'get_beta' ];
					}

					if( isset( $_GET[ 'current_version' ] ) ){
						$this->current_version = $_GET[ 'current_version' ];
					}

					$this->repository_name = strtolower( basename( $this->github_repository ) );

					$this->get_query();
				}
			}

			private function get_query(){
				$response = $this->get_cache();

				if( !$response ){
					$response = $this->remote_query();
					$this -> add_cache( $response );
				}

				$response = $this -> parse_response( $response );
				$this -> query_output( $response );
			}

			private function remote_query() {
				$handle = curl_init();

				if( $handle ) {
					$url = $this->api_url . $this->github_repository . '/releases';
					$url = $this->add_client_secret( $url );

					curl_setopt( $handle, CURLOPT_URL, $url);

					curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 5 );
					curl_setopt( $handle, CURLOPT_TIMEOUT, 5 );

					curl_setopt( $handle, CURLOPT_USERAGENT, 'CherryFramework' );

					curl_setopt( $handle, CURLOPT_POST, false );

					curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
					curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
					//curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, true );
					curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
					curl_setopt( $handle, CURLOPT_CAINFO, dirname(__FILE__) . '/certificates/ca-bundle.crt' );

					curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
					curl_setopt( $handle, CURLOPT_HEADER, false );

					//For local server
					/*curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
					curl_setopt( $handle, CURLOPT_PROXY, '192.168.9.111' );
					curl_setopt( $handle, CURLOPT_PROXYPORT, '3128' );*/

					$response = curl_exec($handle);


					$response_info = curl_getinfo( $handle, CURLINFO_HTTP_CODE );

					if( !$response || $response_info !== 200 ) {
						$error = curl_error($handle).' ( '.curl_errno($handle).' ) ';

						$this -> query_output( $error );
					}
				}
				curl_close($handle);
				return $response;
			}
			private function add_cache( $content ) {
				$cache_file = fopen('cache/' . $this->repository_name . '.cache', 'w');
				fwrite( $cache_file, $content );
				fclose( $cache_file );
			}

			private function get_cache() {
				$file_path = 'cache/' . $this->repository_name . '.cache';

				if ( file_exists( $file_path ) ) {

					$last_change_date = filemtime( $file_path );
					$now_date = strtotime( "now" );

					if( ( $now_date - $last_change_date ) < 60 ){

						$content = file_get_contents( $file_path );
						return $content;

					}else{

						return false;

					}

				}else{

					return false;

				}
			}

			private function parse_response( $response ){
				$response = array_reverse( json_decode( $response ) );
				$last_update = count( $response )-1;
				$last_release = '';

				foreach ($response as $key => $value) {
					$label = $this->get_lable( $value->tag_name );
					$new_version = $this->get_version( $value->tag_name );
					$current_version = $this->get_version( $this->current_version );
					$is_new_version  = version_compare ( $new_version, $current_version ) > 0;

					if( $is_new_version && $key === $last_update && $label !== $this->alpha_release && $label !== $this->beta_release
						|| $label === $this->important_release && $is_new_version
						|| $is_new_version && $key === $last_update && $label === $this->alpha_release && $this->get_alpha === '1'
						|| $is_new_version && $key === $last_update && $label === $this->beta_release && $this->get_beta === '1' )
					{
						return json_encode( $this -> create_package( $value->tag_name, $new_version, $label, $value->zipball_url ) );
					}

					if( !$label && $is_new_version ){
						$last_release = $this->create_package( $value->tag_name, $new_version, $label, $value->zipball_url );
					}
				}

				if( $is_new_version && $last_release){
					return json_encode( $last_release );
				}
				return 'no_update';
			}

			private function create_package( $tag_name, $new_version, $label, $zipball_url ) {
				$update = new stdClass();

				$update->tag_name = strtolower( $tag_name );
				$update->new_version = $new_version;
				$update->details_url = $this->get_details_url( $tag_name );

				$product_folder = ( $this->repository_name === 'cherryframework4' ) ? $this->folder_structure[0] : $this->folder_structure[1] ;
				$label = ( $label === $this->important_release )? '-' . $tag_name : $label ;
				$file = $product_folder . $this->repository_name . $label . '.zip';

				if( $this->repository_name === 'cherryframework4' && file_exists( dirname( __DIR__ ) . '/' . $file ) /* temporarily */ ){
					$update->package =  $this->uploads_url . $file;
				}else{
					$update->package = ( $this->up_query_limit === '1' ) ? $this->add_client_secret( $zipball_url ) : $zipball_url ;
				}

				return $update;
			}

			private function get_lable( $string ) {
				return strtolower( preg_replace( '/[v]?[\d\.]+[v]?/', '', $string ) );
			}

			private function get_version( $string ) {
				return preg_replace( '/[^\d\.]/', '', $string );
			}

			private function get_details_url( $string ) {
				return $this->hub_url . $this->github_repository . '/releases/' . $string;
			}

			private function add_client_secret( $query ) {
				$prefix = ( strpos( '?', $query ) === false ) ? '?' : '&' ;

				$query .= $prefix.'client_id=';
				$query .= '&client_secret=';

				return $query;
			}

			private function query_output( $response ){
				exit( $response );
			}

			private function browser_output(){
				header ("Content-Type: text/html; charset=utf-8");
			?>
				<style type="text/css">
					#page-404{
						position: absolute;
						top: 50%;
						left: 50%;
						margin-top: -146px;
						margin-left: -146px;
					}
					#page-404 h1{
						font: bold 100px/57px 'Arial', sans-serif ;
						text-align: center;
					}
					#page-404 p{
						font: 40px/22px 'Arial', sans-serif ;
						text-align: center;
					}
				</style>

				<div id="page-404">
					<h1>404</h1>
					<p>Page not found</p>
				</div>
			<?php
				exit();
			}
		}

		$Cloud_Updater = new Cloud_Updater();
	}
?>
