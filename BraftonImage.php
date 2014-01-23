<?php
/**
 *	Assumptions: 
 *
 *	Wordpress VIP requires all article attributes to be passed in as a single array
 *	Brafton images must already exist in the feed before passing them through the arguments array.
 * 
 * 	Todo: 	Determine whether a constructor is necessary 
 * 			List global variables if any
 *			List private and public variables that will need to be scoped outside methods
 *			List dependencies
 */


//for downloading images
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');



class BraftonImage {
  

     /**
     *
     * @var array 
     */
     private $args; 	
   


     /* 
      *  Constructor all BraftonImages need domain_url in the feed to be
      *  Downloaded from the feed. Video article images need an api secret.
      */ 
     /***************************************************************************************************
     Not sure if this will work. 
     ***************************************************************************************************/
     private function __construct($args = array() ){  

      if( ! is_array($args) || ! empty($args) )
      {
        //logMsg('I need an array including feed details for a particular image. parameters include $feed_brafton_image_url, caption, brafton_id, post_id, and brafton_image_id');
        continue; 
      }
        foreach( $args as $key => $value ){
          $this->$key = $value; 
        }
     }


	/**
     * Check if uploads directory for the current year/month exists 
	 * and create one if possible
     * @return uploads_directory_array -an array of key => value pairs 
     * 		containing path information on the currently configured uploads directory.
     * @return  or if an error exists return a string value of the 'error' key, from wp upload dir() method call.
     */
	private function uploads_Directory_Exists() {
    $upload_dir = wp_upload_dir();
    
    if ( false == $upload_dir ) {
      // logMsg('Uploads Directory does not exist. Try creating the uploads directory.');
      return $upload_dir; 
    }
    elseif ( true == key($upload_dir['error']))
      // logMsg('Uploads Directory does not exist and could not be created. Check file permissions'); 
      return $upload_dir;
    else
      // logMsg('Uploads Directory exists');
      return $upload_dir;     
  }


	/**
     * Check if article image exists locally in the wordpress database
	 * and create one if possible should return file path if image is found
     * @param 	brafton_image_Id -string, photo id taken from the feed
     * @return boolean -true if image already exists as an attachment in database
     * @throws InvalidArgumentException
     */
	private function is_Found_Locally() {

    $brafton_image_id = $this->args['brafton_image_id']; 

    $args = array(
        'post_type' => 'attachment',
        'fields' => 'ids', 
        'meta_query' => array(
                  array( 
                          'key' => 'pic_id', 
                          'value' => 'brafton_image_id' 
                  )
            )        
       ); 

    $attachments = new WP_Query( $args );
    $attachment_id = $attachment[0];
    $image_attributes = wp_get_attachment_image_src( $attachment_id ); 
    $image_src = $image_attributes[0];  

    $image_file_name = basename($image_src); 

    $upload_dir = wp_upload_dir(); 
    $upload_path = $upload_dir[0];

    $image_local_path = $upload_dir . '/' $image_file_name; 

    $image_exists = file_exists( $path ) );

    if( $image_exists) 
      return $image_local_path; 
    else
      return false;
	}


	/**
     * Update article image (if image already exists locally)
     * @param 	post_id 
     * @return boolean - true if article's post thumbnail is successfully updated
     * @throws InvalidArgumentException		
     */
	/***************************************************************************************************
	Not sure if a featured image's caption can be changed while setting articles post thumbnail. 
	Might be forced to insert a new image attachment with a reference to the existing image's File URL
	***************************************************************************************************/
	private function attach_Brafton_Image( ) {
       
    
    //Find existing image's absolute file path
    $brafton_image_id = $this->args['brafton_image_id'];
    $image_local_path =  is_Found_Locally($brafton_image_id); 
    $post_image_caption = $this->args['caption'];
    $post_id = $this->args['post_id'];
    if( $image_local_path ){
      $wp_filetype = wp_check_filetype($image_local_path), NULL);
    
      $attachment = array(
                          'post_mime_type' => $wp_filetype['type'],
                          'post_title' => $post_image_caption,
                          'post_excerpt' => $post_image_caption,
                          'post_content' => $post_image_caption,
                          'post_status' => 'inherit'
                        );
      //Generate attachment meta data and attach image to the post
      $attachment_id = wp_insert_attachment( $attachment, $image_local_path,  );
      $attachment_data = wp_genererate_metadata( $attachment_id, $local_image_path );
      $wp_update_attachment_metadata($attachment_id, $attachment_data );  
      update_post_meta($post_id ,'_thumbnail_id', $attachment_id);
      update_post_meta($post_id, 'pic_id', $brafton_image_id);
    }
    elseif {
      //We need to download a new image and attach it to the article.
      $image_url = $image_details['feed_brafton_image_url'];  
      $wp_filetype = wp_check_filetype(basename($image_url)), NULL);
    
      $attachment = array(
                          'post_mime_type' => $wp_filetype['type'],
                          'post_title' => $post_image_caption,
                          'post_excerpt' => $post_image_caption,
                          'post_content' => $post_image_caption,
                          'post_status' => 'inherit'
                        );
      //Generate attachment meta data and attach image to the post
      $post_id = $this->args['post_id'];

      $attachment_id = $this->args['feed_brafton_image_url'];
      $attachment_data = wp_genererate_metadata( $attachment_id, $local_image_path );
      $wp_update_attachment_metadata($attachment_id, $attachment_data );  
      update_post_meta($post_id ,'_thumbnail_id', $attachment_id);
      update_post_meta($post_id, 'pic_id', $brafton_image_id);
    }
    else
      logMsg('Image was not found locally and could not be attached to the post.');
    
  }

	/**
     * Download and insert image into the appropriate date folder in the uploads directory or the 
     * uploads directory itself if uploads_use_yearmonth_folders' is set to false 
     * @param 	$feed_brafton_image_url
     * @return attachment id  
     * @throws InvalidArgumentException		
     */
	/***************************************************************************************************
	Still investigating ways around using Fopen and Curl 
	***************************************************************************************************/
	private function download_Brafton_Image( ) {
    $feed_brafton_image_url  = $this->args['feed_brafton_image_url'];
    $image_attachment_id = media_handle_upload( $feed_brafton_image_url );
    return $image_attachment_id; 
	}

}
?>
