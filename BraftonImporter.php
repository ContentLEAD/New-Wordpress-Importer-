<?php
/*
	Plugin Name: Brafton Article Loader
	Plugin URI: http://www.brafton.com/support/wordpress
	Description: A Wordpress 2.9+ plugin designed to download articles from Brafton's API and store them locally, along with attached media.
	Version: 2.0
	Author: Brafton, Inc.
	Author URI: http://brafton.com/support/wordpress
*/

if(!class_exists('BraftonImporter'))
{
	class BraftonImporter
	{
		/**
		 * Construct the plugin object
		 */
		public function __construct()
		{
        	// Initialize Settings
            require_once(sprintf("%s/settings.php", dirname(__FILE__)));
            $BraftonImporterSettings = new BraftonImporterSettings();
            require_once(ABSPATH . 'wp-admin/includes/admin.php');
			require_once(ABSPATH . 'wp-includes/post.php');
			require_once(sprintf("%s/SampleAPIClientLibrary/ApiHandler.php", dirname(__FILE__)));
			require_once(sprintf("%s/BraftonImporterLibrary/BraftonImage.php", dirname(__FILE__)));
			$BraftonImage = new BraftonImage();
			require_once(sprintf("%s/BraftonImporterLibrary/BraftonTerms.php", dirname(__FILE__)));
            $BraftonTerms = new BraftonTerms();
            require_once(sprintf("%s/BraftonImporterLibrary/BraftonScheduler.php", dirname(__FILE__)));
            $BraftonScheduler = new BraftonScheduler();
            require_once(sprintf("%s/BraftonImporterLibrary/BraftonImporterLog.php", dirname(__FILE__)));
            $BraftonImporterLog = new BraftonImporterLog();
			//add_action('wp_head', array(&$this, 'braftonxml_inject_opengraph_tags'));
			//add_filter('language_attributes', array($this, 'braftonxml_inject_opengraph_namespaces', 100));
        	
		} // END public function __construct
	    
		/**
		 * Activate the plugin
		 */
		public static function activate()
		{

		} // END public static function activate
	
		/**
		 * Deactivate the plugin
		 */		
		public static function deactivate()
		{
			

		} // END public static function deactivate


		/**
		 *  Properly formats and returns current page url
		 */	
		public function braftonCurPageURL()
		{
			$pageURL = 'http';
			
			if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER["HTTPS"]) == "on")
				$pageURL .= "s";
			
			$pageURL .= "://";
			
			if ($_SERVER["SERVER_PORT"] != "80")
				$pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
			else
				$pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
			
			return $pageURL;
		}

		/**
		 *  Injects opengraph tags into header
		 */	
		private function braftonxml_inject_opengraph_tags()
		{
			if (!is_single())
				return;
			
			global $post;
			$tags = array(
				'og:type' => 'article',
				'og:site_name' => get_bloginfo('name'),
				'og:url' => braftonCurPageURL(),
				'og:title' => preg_replace('/<.*?>/', '', get_the_title()),
				'og:description' => htmlspecialchars(preg_replace('/<.*?>/', '', get_the_excerpt())),
				'og:image' => wp_get_attachment_url(get_post_thumbnail_id($post->ID)),
				'article:published_time' => date('c', strtotime($post->post_date))
			);
			
			$tagsHtml = '';
			foreach ($tags as $tag => $content)
				$tagsHtml .= sprintf('<meta property="%s" content="%s" />', $tag, $content) . "\n";
			
			echo trim($tagsHtml);
		}


		/**
		 *  Sets namespaces for opengraph tags
		 */	
		private function braftonxml_inject_opengraph_namespaces($content)
		{
			$namespaces = array(
				'xmlns:og="http://ogp.me/ns#"',
				'xmlns:article="http://ogp.me/ns/article#"'
			);
			
			foreach ($namespaces as $ns)
				if (strpos($content, $ns) === false) // don't add attributes twice
					$content .= ' ' . $ns;
			
			return trim($content);
		}



		/**
	 	*  Main article importer function
	 	*  @param string $url    //Base URL: Brafton, Contentlead, or Castleford
	 	*  @param string $API_KEY   //Alphanumeric key that gets appended to based URL
	 	*/	

	 	public function braftonxml_sched_load_articles($url, $API_Key)
		{
			//logMsg("Start Run");
			
			global $wpdb, $post;
			
			//start cURL
			$ch = curl_init();
			
			//Archive upload check
			if ($_FILES['archive']['tmp_name'])
			{
				echo "Archive Option Selected<br/>";
				$articles = NewsItem::getNewsList($_FILES['archive']['tmp_name'], "html");
			}
			else
			{
				if (preg_match("/\.xml$/", $API_Key))
					$articles = NewsItem::getNewsList($API_Key, 'news');
				else
				{
					$fh = new ApiHandler($API_Key, $url);
					$articles = $fh->getNewsHTML();
				}
			}
			
			/*	$catDefsObj = $fh->getCategoryDefinitions();
			
			foreach($catDefsObj as $catDef){
			$catDefs[] = $wpdb->escape($catDef->getName());
			
			}
			wp_create_categories($catDefs);*/
			
			$article_count = count($articles);
			//$counter = 0;
			
			ini_set('magic_quotes_runtime', 0);
			
			//Article Import Loop
			foreach ($articles as $a)
			{
				//if ($counter >= 30)
				//	break; // load 30 articles 
				// Extend PHP timeout limit by X seconds per article
				set_time_limit(20);
				
				//$counter++;
				$brafton_id = $a->getId();
				$articleStatus = "Imported";
				
				if (brafton_post_exists($brafton_id))
				{
					//if the post exists and article edits will automatically overwrite 
					if (get_option("braftonxml_sched_triggercount") % 10 != 0)
					{
						//Every ten importer runs do not skip anything
						$articleStatus = "Updated";
						continue;
					}
				}
				
				switch (get_option('braftonxml_publishdate'))
				{
					case 'modified':
						$date = $a->getLastModifiedDate();
						break;
					
					case 'created':
						$date = $a->getCreatedDate();
						break;
					
					default:
						$date = $a->getPublishDate();
						break;
				}
				
				//Id, Title, Content, Author, Post Status

				$post_id = brafton_post_exists($brafton_id);
				$post_title = $a->getHeadline();
				$post_content = $a->getText();
				$post_author = apply_filters('braftonxml_author', get_option("braftonxml_default_author", 1));
				if ($post_id)
					$post_status = get_post_status($post_id);
				else
					$post_status = get_option("braftonxml_sched_status", "publish");

				// Date and Content Formatting
				$post_date;
				$post_date_gmt;
				$post_date_gmt = strtotime($date);
				$post_date_gmt = gmdate('Y-m-d H:i:s', $post_date_gmt);
				$post_date = get_date_from_gmt($post_date_gmt);
				$post_content = preg_replace('|<(/?[A-Z]+)|e', "'<' . strtolower('$1')", $post_content);
				$post_content = str_replace('<br>', '<br />', $post_content);
				$post_content = str_replace('<hr>', '<hr />', $post_content);
				

				//Post Excerpt
				if (get_option("braftonxml_domain") == 'api.castleford.com.au')
					$post_excerpt = $a->getHtmlMetaDescription();
				else
					$post_excerpt = $a->getExtract();
				
				$keywords = $a->getKeywords();

				//Images

				$photos = $a->getPhotos();
				$photo_option = 'large';
				$post_image = null;
				$post_image_caption = null;
				
				// Download main image to Wordpress uploads directory (faster page load times)
				// [citation needed] -brian 2013.05.03
				$upload_array = wp_upload_dir();

				//Check if picture exists
				if (!empty($photos))
				{
					if ($photo_option == 'large') //Large photo
						$image = $photos[0]->getLarge();
					
					if (!empty($image))
					{
						$post_image_url = $image->getUrl();
						$post_image_caption = $photos[0]->getCaption();
						$image_id = $photos[0]->getId();
					}
				}


				//Attach image
				$imageAttach = $BraftonImage->attach_Brafton_Image($brafton_id,$post_image_url, $post_image_caption, $image_id, $post_id); 

				//Why is this here?
				$guid = $API_Key;

				// Save the article to the articles array
				$article = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_status', 'post_excerpt');
				
				// Category handling

				// TODO: tag/category switching based on GUI
				
				$tag_option = get_option("braftonxml_sched_tags", 'cats');
				$cat_option = get_option("braftonxml_sched_cats");
				$custom_cat = explode(",", get_option("braftonxml_sched_cats_input"));
				$custom_tags = explode(",", get_option("braftonxml_sched_tags_input"));
				$CatColl = $a->getCategories();
				$TagColl = $a->getTags();
				
				$this->brafton_set_taxon($article, $tag_option, $cat_option, $custom_cat, $custom_tags);
				

				if ($post_id)
				{
					$article['ID'] = $post_id;
					if (get_option("braftonxml_overwrite", "on") == 'on')
						wp_update_post($article);
					
					if (populate_postmeta($article_count, $post_id, $image_id))
					{
						$update_image = image_update($post_id, $image_id);
						if (empty($update_image))
						{
							if ($local_image_path)
							{
								$wp_filetype = wp_check_filetype(basename($local_image_path), NULL);
								$attachment = array(
									'post_mime_type' => $wp_filetype['type'],
									'post_title' => $post_image_caption,
									'post_excerpt' => $post_image_caption,
									'post_content' => $post_image_caption,
									'post_status' => 'inherit'
								);
								
								// Generate attachment information & set as "Featured image" (Wordpress 2.9+ feature, support must be enabled in your theme)
								$attach_id = wp_insert_attachment($attachment, $local_image_path, $post_id);
								$attach_data = wp_generate_attachment_metadata($attach_id, $local_image_path);
								wp_update_attachment_metadata($attach_id, $attach_data);
								update_post_meta($post_id, '_thumbnail_id', $attach_id);
								update_post_meta($post_id, 'pic_id', $image_id);
							}
						}
					}
				}
				else
				{
					// insert new story
					$post_id = wp_insert_post($article);
					if (is_wp_error($post_id))
						return $post_id;
					
					if (!$post_id)
						return;
					
					add_post_meta($post_id, 'brafton_id', $brafton_id, true);
					
					// castleford uses a secondary title for keyword quotas
					// this is a stopgap. -brian 06.06.2013
					$seoTitle = $post_title;
					$htmlTitle = $a->getHtmlTitle();
					if (get_option("braftonxml_domain") == 'api.castleford.com.au' && !empty($htmlTitle))
						$seoTitle = $htmlTitle;
					
					// All-in-One SEO Plugin integration
					if (function_exists('aioseop_get_version'))
					{
						add_post_meta($post_id, '_aioseop_description', $post_excerpt, true);
						add_post_meta($post_id, '_aioseop_keywords', $keywords, true);
					}
					
					// Check if Yoast's Wordpress SEO plugin is active...if so, add relevant meta fields, populated by post info
					if (is_plugin_active('wordpress-seo/wp-seo.php'))
					{
						add_post_meta($post_id, '_yoast_wpseo_title', $seoTitle, true);
						add_post_meta($post_id, '_yoast_wpseo_metadesc', $post_excerpt, true);
					}
					
					if ($local_image_path)
					{
						
						//Attach image
						$attach_image = $BraftonImage->attach_Brafton_Image($brafton_id,$post_image_url, $post_image_caption, $image_id, $post_id); 

						$wp_filetype = wp_check_filetype(basename($local_image_path), NULL);
						$attachment = array(
							'post_mime_type' => $wp_filetype['type'],
							'post_title' => $post_image_caption,
							'post_excerpt' => $post_image_caption,
							'post_content' => $post_image_caption
						);
						
						// Generate attachment information & set as "Featured image" (Wordpress 2.9+ feature, support must be enabled in your theme)
						$attach_id = wp_insert_attachment($attachment, $local_image_path, $post_id);
						$attach_data = wp_generate_attachment_metadata($attach_id, $local_image_path);
						wp_update_attachment_metadata($attach_id, $attach_data);
						
						add_post_meta($post_id, '_thumbnail_id', $attach_id, true);
						add_post_meta($post_id, 'pic_id', $image_id, true);
					}
				}

			}
		}
			  // END public function braftonxml_sched_load_articles()

			/**
		 	*  Adds respective taxonomy to article array
		 	*  @param array $article
		 	*  @param string $tag_option
		 	*  @param string $cat_option
		 	*  @param array $custom_cat
		 	*  @param array $custom_tags
		 	*/	

			private function brafton_set_taxon($article, $tag_option, $cat_option, $custom_cat, $custom_tags)
			{
				$categories = array();
				$tags_input = array();
				// categories
				if ($cat_option == 'categories' && $custom_cat[0] != "") // 'category' option is selected and custom tags inputed
				{
					foreach ($CatColl as $c)
						$categories[] = $wpdb->escape($c->getName());
					
					for ($j = 0; $j < count($custom_cat); $j++)
						$categories[] = $custom_cat[$j];
					$article['post_category'] = wp_create_categories($categories);
				}
				else if ($cat_option == 'none_cat' && $custom_cat[0] != "")
				{
					$cat_name = array();
					$name = array();
					
					$cat_query = "SELECT terms.name FROM " . $wpdb->terms . " terms, " . $wpdb->term_taxonomy . " tax 
					WHERE terms.term_id=tax.term_id AND 
					tax.taxonomy='category'";
					$cat_name []= $wpdb->get_results($cat_query);
					
					for ($j = 0; $j < count($custom_cat); $j++)
						$categories[] = $custom_cat[$j];
					
					for ($x = 0; $x < count($cat_name); $x++)
						for ($z = 0; $z < count($cat_name[$x]); $z++)
							$name[] = $cat_name[$x][$z]->name;
					
					foreach ($CatColl as $c)
						if ((in_array($c->getName(), $name)))
							$categories[] = $wpdb->escape($c->getName());
					$article['post_category'] = wp_create_categories($categories);
				}
				else if ($cat_option == 'categories' && $custom_cat[0] == "")
				{
					foreach ($CatColl as $c)
						$categories[] = $wpdb->escape($c->getName());
					$article['post_category'] = wp_create_categories($categories);
				}
				
				// tags
				if ($tag_option == 'cats' && $custom_tags[0] != "")
				{
					foreach ($CatColl as $c)
						$tags_input[] = $wpdb->escape($c->getName());
					
					for ($j = 0; $j < count($custom_tags); $j++)
						$tags_input[] = $custom_tags[$j];
					$article['tags_input'] = $tags_input;
				}
				else if ($tag_option == 'none_tags' && $custom_tags[0] != "")
				{
					$tname = array();
					$name = array();
					
					$tax_query = "SELECT terms.name FROM " . $wpdb->terms . " terms, " . $wpdb->term_taxonomy . " tax 
					WHERE terms.term_id=tax.term_id AND 
					tax.taxonomy='post_tag'";
					$tname []= $wpdb->get_results($tax_query);
					
					for ($j = 0; $j < count($custom_tags); $j++)
						$tags_input[] = $custom_tags[$j];
					
					for ($x = 0; $x < count($tname); $x++)
						for ($z = 0; $z < count($tname[$x]); $z++)
							$name[] = $tname[$x][$z]->name;
					
					foreach ($CatColl as $c)
						if ((in_array($c->getName(), $name)))
							$tags_input[] = $wpdb->escape($c->getName());
					$article['tags_input'] = $tags_input;
				}
				else if ($tag_option == 'cats' && $custom_tags[0] == "")
				{
					foreach ($CatColl as $c)
						$tags_input[] = $wpdb->escape($c->getName());
					$article['tags_input'] = $tags_input;
				}
				else if ($tag_option == 'keywords' && ($custom_tags[0] == ""))
				{
					if (!empty($keywords))
					{
						$keyword_arr = explode(',', $keywords);
						foreach ($keyword_arr as $keyword)
							$article['tags_input'][] = trim($keyword);
					}
				}
				else if ($tag_option == 'keywords' && $custom_tags[0] != "")
				{
					if (!empty($keywords))
					{
						$tname = array();
						$name = array();
						
						$tax_query = "SELECT terms.name FROM " . $wpdb->terms . " terms, " . $wpdb->term_taxonomy . " tax 
						WHERE terms.term_id=tax.term_id AND 
						tax.taxonomy='post_tag'";
						$tname []= $wpdb->get_results($tax_query);
						
						for ($j = 0; $j < count($custom_tags); $j++)
							$tags_input[] = $custom_tags[$j];
						
						for ($x = 0; $x < count($tname); $x++)
							for ($z = 0; $z < count($tname[$x]); $z++)
								$name[] = $tname[$x][$z]->name;
						$keyword_arr = explode(',', $keywords);
						
						foreach ($keyword_arr as $keyword)
							$tags_input[] = trim($keyword);
						
						foreach ($CatColl as $c)
							if ((in_array($c->getName(), $name)))
								$tags_input[] = $wpdb->escape($c->getName());
						$article['tags_input'] = $tags_input;
					}
				}
				else if ($tag_option == 'tags' && $custom_tags[0] == "")
				{
					$TagCollArray = explode(',', $TagColl);
					foreach ($TagCollArray as $c)
						$tags_input[] = $wpdb->escape($c);
					
					for ($j = 0; $j < count($custom_tags); $j++)
						$tags_input[] = $custom_tags[$j];
					$article['tags_input'] = $tags_input;
				}
				else if ($tag_option == 'tags' && $custom_tags[0] == "")
				{
					$TagCollArray = explode(',', $TagColl);
					foreach ($TagCollArray as $c)
						$tags_input[] = $wpdb->escape($c);
					$article['tags_input'] = $tags_input;
				}
			}
		 // END public function brafton_set_taxon

			private function duplicateKiller()
			{
				global $wpdb;
				//grab post_id for all posts with a brafton ID associated with them
				$braftonPosts = $wpdb->get_col("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'brafton_id'");

				foreach( $braftonPosts as $postID )
				{
					//grab brafton_id of post to check for copies of
					$braftonID = get_post_meta( $postID, 'brafton_id', true );
					
					//grab brafton title (double checkin')
					$braftonTitle = get_the_title($postID);
					
					$i = 0;
					
					foreach( $braftonPosts as $innerPost )
					{
						//savin resources, yessah
						if( $postID == $innerPost ) continue;
						
						//get brafton ID for comparison
						$toCompare = get_post_meta($innerPost, 'brafton_id', true);
						
						//get title for comparison
						$titleCompare = get_the_title($innerPost);
						
						//if a post is found with matching "brafton_id"s but different "post_id"s, we have a dupe!
						if( $braftonID == $toCompare )
						{
							//delete $innerPost from WP database
							wp_delete_post( $innerPost, true );
							//...and remove from array of posts to be compared (since it no longer exists)
							unset( $braftonPosts[$i] );
						} else if ( $braftonTitle == $titleCompare )
						{
							//delete $innerPost from WP database
							wp_delete_post( $innerPost, true );
							//...and remove from array of posts to be compared (since it no longer exists)
							unset( $braftonPosts[$i] );
						}
						
						$i++;
					}
				}
			} // END public function duplicate killer()

			/**
		 	*  
		 	*  Populates article post meta fields
		 	*  @param int $article_count
		 	*  @param int $post_id
		 	*  @param int $image_id
		 	*
		 	*/	

			private function populate_postmeta($article_count, $post_id, $image_id)
			{
				global $wpdb;
				$value = get_option("braftonxml_pic_id_count");
				
				if (!empty($value) && $value < $article_count && $value != "completed" && !empty($image_id))
				{
					add_post_meta($post_id, 'pic_id', $image_id, true);
					$value++;
					update_option("braftonxml_pic_id_count", $value);
					
					if ($value == $article_count || $value == 31)
						update_option("braftonxml_pic_id_count", "completed");
					
					return false;
				}
				else if (empty($value) && !empty($image_id))
				{
					update_option("braftonxml_pic_id_count", 1);
					add_post_meta($post_id, 'pic_id', $image_id, true);
					return false;
				}
				else
					return true;
			} // END public function populate_postmeta()

			/**
		 	*  Checks if Brafton ID exists in database. If it does, returns post id.
		 	*  @param int $brafton_id
		 	*
		 	*/	

			public function brafton_post_exists($brafton_id)
			{
				global $wpdb;
				$query = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE 
							meta_key = 'brafton_id' AND  meta_value = '%d'", $brafton_id);
				$post_id = $wpdb->get_var($query);
				
				$query = $wpdb->prepare("SELECT id FROM $wpdb->posts WHERE 
							id = '%d'", $post_id);
				$exists = $wpdb->get_var($query);
				
				/*if(!isset($exists)) {
				$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_value = '".$brafton_id."'");
				}
				
				//Delete all revisions on Brafton content - the plugin tends to bloat the DB with unneeded revisions
				if($post_id != null) {
				$wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'revision' AND ID=".$post_id);
				}*/
				
				return $post_id;

			} // END public function brafton_post_exists()

			/**
		 	*  
		 	*  @param int $post_id
		 	*
		 	*/	

			public function brafton_post_modified($post_id)
			{
				global $wpdb;
				$query = $wpdb->prepare("SELECT post_modified FROM $wpdb->posts WHERE 
							post_id = '%d'", $post_id);
				$post_modified = $wpdb->get_var($query);
				return $post_modified;

			} // END public function brafton_post_modified()


	} // END class BraftonImporter

} // END if(!class_exists('BraftonImporter'))

if(class_exists('BraftonImporter'))
{
	// Installation and uninstallation hooks
	register_activation_hook(__FILE__, array('BraftonImporter', 'activate'));
	register_deactivation_hook(__FILE__, array('BraftonImporter', 'deactivate'));

	// instantiate the plugin class
	$BraftonImporter = new BraftonImporter();
	
    // Add a link to the settings page onto the plugin page
    if(isset($BraftonImporter))
    {
        // Add the settings link to the plugins page
        function plugin_settings_link($links)
        { 
            $settings_link = '<a href="options-general.php?page=BraftonImporter">Settings</a>'; 
            array_unshift($links, $settings_link); 
            return $links; 
        }

        $plugin = plugin_basename(__FILE__); 
        add_filter("plugin_action_links_$plugin", 'plugin_settings_link');
    }
}