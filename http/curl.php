<?php namespace lib\http {
  
  use \lib\http;
  
  /**
   * HTTP cURL transport layer
   * 
   * @copyright 2012 Jonas Hermsmeier
   * @author Jonas Hermsmeier <http://jhermsmeier.de>
   */
  class curl {
    
    /**
     * Checks if this transport is available.
     * 
     * @return bool
     */
    public static function available() {
      
      if( !function_exists( 'curl_init' ) ||
          !function_exists( 'curl_exec' ) ||
          !( $version = curl_version() )  ||
          !( $version['features'] & CURL_VERSION_SSL ) )
        return FALSE;
      
      return TRUE;
      
    }
    
    // temporary header storage
    private static $headers = '';
    
    /**
     * Each header is sent to this callback, so we append to
     * the static $header property for temporary storage
     * and later processing, once the request finishes.
     * 
     * @param resource $ch 
     * @param string $headers 
     * @return int
     */
    private static function streamHeaders( $ch, $headers ) {
      self::$headers.= $headers;
      return strlen( $headers );
    }
    
    /**
     * Since cURL requires an indexed array of
     * header field strings, we'll need to convert
     * the $opt['header'] array.
     * 
     * @param type $header 
     * @return type
     */
    private static function buildHeader( $input ) {
      $header = array();
      foreach( $input as $key => $value )
        $header[] = "{$key}: {$value}";
      return $header;
    }
    
    /**
     * Performs a HTTP request on the supplied url.
     * 
     * @param string $url 
     * @param array $opt 
     * @return mixed
     */
    public static function request( $url, $opt = array() ) {
      // FOLLOW_LOCATION DOES NOT work if safe mode or open basedir
      // are enabled (this check will be obsolete as of PHP 5.4)
      if( ini_get( 'safe_mode' ) && ini_get( 'open_basedir' ) )
        $opt['redirect'] = 0;
      // I want uppercase request methods
      $opt['method'] = strtoupper( $opt['method'] );
      // cURL WILL NOT handle floats
      $opt['timeout'] = ceil( $opt['timeout'] );
      // build query string from body data
      if( !empty( $opt['body'] ) )
        $opt['body'] = !is_array( $opt['body'] ) ?: http::buildQuery( $opt['body'] );
      // init cURL handle
      $ch = curl_init();
      // set post field for POST and PUT requests
      if( $opt['method'] === 'POST' || $opt['method'] === 'PUT' )
        curl_setopt( $handle, CURLOPT_POSTFIELDS, $opt['body'] );
      elseif( !empty( $opt['body'] ) )
        $url.= '?'.$opt['body'];
      // set/merge defaults and opts
      curl_setopt_array( $ch, array(
        CURLOPT_HEADER          => FALSE,
        CURLOPT_HEADERFUNCTION  => array( 'self', 'streamHeaders' ),
        CURLOPT_RETURNTRANSFER  => TRUE,
        CURLOPT_CRLF            => TRUE,
        CURLOPT_CUSTOMREQUEST   => $opt['method'],
        CURLOPT_HTTPHEADER      => self::buildHeader( $opt['header'] ),
        CURLOPT_NOBODY          => $opt['method'] === 'HEAD',
        CURLOPT_TIMEOUT         => $opt['timeout'],
        CURLOPT_CONNECTTIMEOUT  => $opt['timeout'],
        CURLOPT_FOLLOWLOCATION  => !!$opt['redirect'],
        CURLOPT_MAXREDIRS       => $opt['redirect'],
        CURLOPT_SSL_VERIFYHOST  => $opt['ssl_verify'] ? 2 : FALSE,
        CURLOPT_SSL_VERIFYPEER  => $opt['ssl_verify'],
        CURLOPT_USERAGENT       => $opt['useragent'],
        CURLOPT_URL             => $url
      ));
      // stream transfer to file?
      if( !empty( $opt['file'] ) && file_exists( dirname( $opt['file'] ) ) ) {
        if( $stream = fopen( $opt['file'], 'w+' ) )
          curl_setopt( $ch, CURLOPT_FILE, $stream );
        else
          throw new \exception( 'HTTP request failed' );
      }
      // get response & info
      $data = curl_exec( $ch );
      $info = curl_getinfo( $ch );
      // close curl handle and free resource
      curl_close( $ch );
      unset( $ch );
      // parse header and flush self::$header
      $header = http::parseHeader( self::$headers );
      self::$headers = '';
      // return response
      return array(
        'status'  => $info['http_code'],
        'headers' => $header,
        'body'    => $data
      );
    }
    
  }
  
} ?>