<?php namespace {
  
  require 'http.php';
  require 'http/curl.php';
  
  $response = lib\http::request( 'example.com' );
  
  print_r( $response );
  
} ?>