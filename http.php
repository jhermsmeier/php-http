<?php namespace lib {
  
  if( !function_exists( 'http_build_url' ) ) {
    define( 'HTTP_URL_REPLACE',        1 );    // Replace every part of the first URL when there's one of the second URL
    define( 'HTTP_URL_JOIN_PATH',      2 );    // Join relative paths
    define( 'HTTP_URL_JOIN_QUERY',     4 );    // Join query strings
    define( 'HTTP_URL_STRIP_USER',     8 );    // Strip any user authentication information
    define( 'HTTP_URL_STRIP_PASS',     16 );   // Strip any password authentication information
    define( 'HTTP_URL_STRIP_AUTH',     32 );   // Strip any authentication information
    define( 'HTTP_URL_STRIP_PORT',     64 );   // Strip explicit port numbers
    define( 'HTTP_URL_STRIP_PATH',     128 );  // Strip complete path
    define( 'HTTP_URL_STRIP_QUERY',    256 );  // Strip query string
    define( 'HTTP_URL_STRIP_FRAGMENT', 512 );  // Strip any fragments (//identifier)
    define( 'HTTP_URL_STRIP_ALL',      1024 ); // Strip anything but scheme and host
  }
  
  /**
   * HTTP
   * 
   * @copyright 2012 Jonas Hermsmeier
   * @author Jonas Hermsmeier <http://jhermsmeier.de>
   */
  class http {
    
    // TODO: add socket and stream transports
    private static $transports = [ 'curl' ];
    
    /**
     * Redirects to the given url.
     * 
     * To be RFC compliant, "Redirecting to <a>URL</a>."
     * will be displayed, if the client doesn't redirect immediately,
     * and the request method was another one than HEAD.
     * 
     * @param string $url 
     * @param int $status 
     * @return NULL
     */
    public static function redirect( $url, $status = 302 ) {
      $url = self::buildUrl( NULL, parse_url( $url ) );
      header( "Location: {$url}", TRUE, $status );
      if( $_SERVER['REQUEST_METHOD'] !== 'HEAD' ) {
        $url = htmlentities( $url, ENT_QUOTES, 'UTF-8', FALSE );
        exit( "Redirecting to <a href=\"{$url}\">{$url}</a>" );
      }
      exit();
    }
    
    /**
     * Performs a HTTP request on the supplied url.
     * 
     * @param string $url 
     * @param array $opt 
     * @return mixed
     */
    public static function request( $url, $opt = [] ) {
      // get or construct default user agent
      $useragent = ( function_exists( 'ini_get' ) ) ?
        ini_get( 'user_agent' ) : 'php/'.PHP_VERSION;
      // merge options
      $opt = $opt + [
        'method'     => 'GET',
        'header'     => [],
        'body'       => NULL,
        'timeout'    => 3,
        'redirect'   => 5,
        'useragent'  => $useragent,
        'ssl_verify' => TRUE,
        'file'       => NULL
      ];
      // check for available transports
      foreach( self::$transports as $transport ) {
        $transport = __CLASS__."\\{$transport}";
        if( $transport::available() )
          return $transport::request( $url, $opt );
      }
      // just return false on error or
      // maybe throw an exception? decision to be made.
      return FALSE;
    }
    
    /**
     * A better url_encode. Period.
     * 
     * @param string $input 
     * @return string
     */
    public static function urlEncode( $input ) {
      if( is_array( $input ) )
        return array_map( [ 'self', 'urlEncode' ], $input );
      else if( is_scalar( $input ) )
        return str_replace( '+', ' ', str_replace( '%7E', '~', rawurlencode( $input ) ) );
      else
        return '';
    }
    
    /**
     * Parses a URL parameter string into an
     * associative (or indexed) array.
     * 
     * @param string $input 
     * @return array
     */
    public static function parseParameters( $input ) {
      // split into param array
      $pairs = explode( '&', $input );
      // split each param into key/value pairs
      $params = [];
      foreach( $pairs as &$pair ) {
        if( strpos( $pair, '=' ) ) {
          $pair = explode( '=', $pair );
          if( preg_match( '{^[1-9][0-9]*$}', $pair[1] ) )
            $pair[1] = intval( $pair[1] );
          elseif( preg_match( '{^(true|false)*$}i', $pair[1] ) )
            $pair[1] = ( strtolower($pair[1]) === 'true' ) ? TRUE : FALSE;
          else
            $pair[1] = urldecode( $pair[1] );
          $params[ $pair[0] ] = $pair[1];
        }
        else
          $params[ $pair[0] ] = NULL;
      }
      return $params;
    }
    
    /**
     * Parses a raw HTTP header
     * into an multidimensional array.
     * 
     * TODO: make it http_parse_headers compliant.
     * ("Folded: works\r\n\ttoo\r\n" and duplicate
     * headerfield parsing is missing)
     * 
     * @param string $input 
     * @return mixed
     */
    public static function parseHeader( $input ) {
      // normalize line endings
      $input = str_replace( [ "\r\n", "\r" ], "\n", $input );
      // split into headers (for when multiple were sent (redirects))
      $input = preg_split( '/\n{2,}/', trim( $input ) );
      // convert each header into an associative array
      foreach( $input as &$header ) {
        // split header lines
        $lines = preg_split( '/\n/', $header );
        // get the protocol and status
        preg_match( '{^([a-z]*?)[/](.*?)\s([0-9]*?)\s([a-z]*?)}i', array_shift( $lines ), $matches );
        // flush $header
        $header = [
          'protocol' => $matches[1],
          'protocol_version' => $matches[2],
          'status_code' => (int) $matches[3],
          'status' => $matches[4],
        ];
        // build header array with key/value pairs
        foreach( $lines as &$line ) {
          $line = trim( $line );
          list( $key, $value ) = preg_split( '/[:]/', $line, 2 );
          $key = strtolower( str_replace( '-', '_', $key ) );
          $header[$key] = trim( $value );
        }
      }
      // reverse header order (so last sent has index of 0)
      return array_reverse( $input );
    }
    
    /**
     * Generates a URL-encoded query string
     * from the associative (or indexed) array provided.
     * 
     * @param mixed $params 
     * @return string
     */
    public static function buildQuery( $params ) {
      if( ( version_compare( PHP_VERSION, '5.4.0' ) >= 0 ) )
        return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
      else {
        // gettin' nothin', givin' nothin' back
        if( empty( $params ) )
          return '';
        // cast (possible) object to array
        $params = (array) $params;
        // urlencode keys and values seperately
        $params = array_combine(
          self::urlEncode( array_keys( $params ) ),
          self::urlEncode( array_values( $params ) )
        );
        // sort parameters by name, using lexicographical byte value ordering
        uksort( $params, 'strcmp' );
        // walk through params to sort those having the same name
        $pairs = [];
        foreach( $params as $key => &$value ) {
          if( is_array( $value ) ) {
            natsort( $value );
            foreach( $value as &$duplicate )
              $pairs[] = "{$key}={$duplicate}";
          }
          else
            $pairs[] = "{$key}={$value}";
        }
        // return the key value pairs, seperated by '&'
        return implode( '&', $pairs );
      }
    }
    
    /**
     * Builds a URL. The parts of the second URL will be
     * merged into the first according to the flags argument.
     * 
     * @param mixed $url 
     * @param mixed $parts 
     * @param int $flags 
     * @param array &$new_url 
     * @return string
     */
    public static function buildUrl( $url = NULL, $parts = [], $flags = HTTP_URL_REPLACE, &$new_url = FALSE ) {
      // call http_build_url, if built in
      if( function_exists( 'http_build_url' ) )
        return http_build_url( $url, $parts, $flags, $new_url );
      // part keys
      static $keys = [ 'user', 'pass', 'port', 'path', 'query', 'fragment' ];
      // implementing an undocumented feature:
      // when calling without any parameters,
      // it returns the full url of the page being accessed
      if( empty( $url ) ) {
        $url = strpos( $_SERVER['SERVER_PROTOCOL'], 'HTTPS' ) ? 'https' : 'http';
        $url.= '://'.$_SERVER['HTTP_HOST'];
        $url.= $_SERVER['REQUEST_URI'];
      }
      // HTTP_URL_STRIP_ALL becomes all the HTTP_URL_STRIP_Xs
      if( $flags & HTTP_URL_STRIP_ALL ) {
        $flags |= HTTP_URL_STRIP_USER;
        $flags |= HTTP_URL_STRIP_PASS;
        $flags |= HTTP_URL_STRIP_PORT;
        $flags |= HTTP_URL_STRIP_PATH;
        $flags |= HTTP_URL_STRIP_QUERY;
        $flags |= HTTP_URL_STRIP_FRAGMENT;
      }
      // HTTP_URL_STRIP_AUTH becomes HTTP_URL_STRIP_USER and HTTP_URL_STRIP_PASS
      else if( $flags & HTTP_URL_STRIP_AUTH ) {
        $flags |= HTTP_URL_STRIP_USER;
        $flags |= HTTP_URL_STRIP_PASS;
      }
      // parse the original url
      if( !is_string( $url ) ) $parse_url = (array) $url;
      else $parse_url = parse_url( $url );
      // make $parts
      if( !is_string( $parts ) ) $parts = (array) $parts;
      else $parts = parse_url( $parts );
      // scheme and host are always replaced
      if( isset( $parts['scheme'] ) )
        $parse_url['scheme'] = $parts['scheme'];
      if( isset( $parts['host'] ) )
        $parse_url['host'] = $parts['host'];
      // (if applicable) replace the original url with it's new parts
      if( $flags & HTTP_URL_REPLACE ) {
        foreach( $keys as &$key ) {
          if( isset( $parts[$key] ) )
            $parse_url[$key] = $parts[$key];
        }
      }
      else {
        // join the original URL path with the new path
        if( isset( $parts['path'] ) && ( $flags & HTTP_URL_JOIN_PATH ) ) {
          if( isset( $parse_url['path'] ) )
            $parse_url['path'] = rtrim( str_replace( basename( $parse_url['path'] ), '', $parse_url['path'] ), '/' ).'/'.ltrim( $parts['path'], '/' );
          else
            $parse_url['path'] = $parts['path'];
        }
        // join the original query string with the new query string
        if( isset( $parts['query'] ) && ( $flags & HTTP_URL_JOIN_QUERY ) ) {
          if( isset( $parse_url['query'] ) )
            $parse_url['query'].= '&'.$parts['query'];
          else
            $parse_url['query'] = $parts['query'];
        }
      }
      // strips all the applicable sections of the URL
      // note: scheme and host are never stripped
      foreach( $keys as &$key ) {
        if( $flags & (int) constant( 'HTTP_URL_STRIP_'.strtoupper( $key ) ) )
          unset( $parse_url[$key] );
      }
      // set reference to built url
      $new_url = $parse_url;
      // return black conditional magic
      return
         ( ( isset( $parse_url['scheme'] ) ) ? $parse_url['scheme'].'://' : '' )
        .( ( isset( $parse_url['user'] ) ) ? $parse_url['user'].( ( isset( $parse_url['pass'] ) ) ? ':'.$parse_url['pass'] : '' ).'@' : '' )
        .( ( isset( $parse_url['host'] ) ) ? $parse_url['host'] : '' )
        .( ( isset( $parse_url['port'] ) ) ? ':'.$parse_url['port'] : '' )
        .( ( isset( $parse_url['path'] ) ) ? $parse_url['path'] : '' )
        .( ( isset( $parse_url['query'] ) ) ? '?' . $parse_url['query'] : '' )
        .( ( isset( $parse_url['fragment'] ) ) ? '#'.$parse_url['fragment'] : '' )
      ;
    }
    
  }
  
} ?>