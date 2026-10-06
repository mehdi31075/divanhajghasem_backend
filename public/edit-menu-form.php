<?php
	include_once('includes/connect_database.php'); 
	include_once('functions.php'); 
	require_once("thumbnail_images.class.php");
?>

	<?php 
	
		if(isset($_GET['id'])){
			$ID = $_GET['id'];
		}else{
			$ID = "";
		}
		
		// create array variable to store category data
		$category_data = array();
			
		$sql_query = "SELECT cid, category_name 
				FROM tbl_news_category 
				ORDER BY cid ASC";
				
		$stmt_category = $connect->stmt_init();
		if($stmt_category->prepare($sql_query)) {	
			// Execute query
			$stmt_category->execute();
			// store result 
			$stmt_category->store_result();
			$stmt_category->bind_result($category_data['cid'], 
				$category_data['category_name']
				);
				
		}
			
		$sql_query = "SELECT news_image FROM tbl_news WHERE nid = ?";
		
		$stmt = $connect->stmt_init();
		if($stmt->prepare($sql_query)) {	
			// Bind your variables to replace the ?s
			$stmt->bind_param('s', $ID);
			// Execute query
			$stmt->execute();
			// store result 
			$stmt->store_result();
			$stmt->bind_result($previous_news_image);
			$stmt->fetch();
			$stmt->close();
		}
		
		
		if(isset($_POST['btnEdit'])){
			
			$news_heading = $_POST['news_heading'];
			$cid = $_POST['cid'];
			$news_date = $_POST['news_date'];
			$news_description = $_POST['news_description'];
			
			// get image info
			// $news_image = $_FILES['news_image']['name'];
			// $image_error = $_FILES['news_image']['error'];
			// $image_type = $_FILES['news_image']['type'];
				
			// create array variable to handle error
			$error = array();
			
			if(empty($news_heading)){
				$error['news_heading'] = " <span class='label label-danger'>Required, please fill out this field!!</span>";
			}
				
			if(empty($cid)){
				$error['cid'] = " <span class='label label-danger'>Required, please fill out this field!!</span>";
			}				
				
			if(empty($news_date)){
				$error['news_date'] = " <span class='label label-danger'>Required, please fill out this field!!</span>";
			}			

			if(empty($news_description)){
				$error['news_description'] = " <span class='label label-danger'>Required, please fill out this field!!</span>";
			}
			
			// common image file extensions
			//$allowedExts = array("gif", "jpeg", "jpg", "png");
			
			// get image file extension
			// error_reporting(E_ERROR | E_PARSE);
			// $extension = end(explode(".", $_FILES["news_image"]["name"]));
			
			// if(!empty($news_image)){
			// 	if(!(($image_type == "image/gif") || 
			// 		($image_type == "image/jpeg") || 
			// 		($image_type == "image/jpg") || 
			// 		($image_type == "image/x-png") ||
			// 		($image_type == "image/png") || 
			// 		($image_type == "image/pjpeg")) &&
			// 		!(in_array($extension, $allowedExts))){
					
			// 		$error['news_image'] = "*<span class='label label-danger'>Image type must jpg, jpeg, gif, or png!</span>";
			// 	}
			// }
			
					
			if( !empty($news_heading) && 
				!empty($cid) && 
				!empty($news_date) && 
				!empty($news_description) ) {			
					
					// updating all data except image file
					$sql_query = "UPDATE tbl_news 
							SET news_heading = ? , cat_id = ?, 
							news_date = ?, news_description = ? 
							WHERE nid = ?";
							
					$stmt = $connect->stmt_init();
					if($stmt->prepare($sql_query)) {	
						// Bind your variables to replace the ?s
						$stmt->bind_param('sssss', 
									$news_heading, 
									$cid,
									$news_date, 
									$news_description,
									$ID);
						// Execute query
						$stmt->execute();
						// store result 
						$update_result = $stmt->store_result();
						$stmt->close();
					}
				
					
				// check update result
				if($update_result){
					$error['update_data'] = "<div class='card-panel teal lighten-2'>
											    <span class='white-text text-darken-2'>
												    مطلب با موفقیت بروزرسانی شد
											    </span>
											</div>";
				}else{
					$error['update_data'] = "<div class='card-panel red darken-1'>
											    <span class='white-text text-darken-2'>
												    Update Failed
											    </span>
											</div>";
				}
			}
			
		}
		
		// create array variable to store previous data
		$data = array();
			
		$sql_query = "SELECT * FROM tbl_news WHERE nid = ?";
			
		$stmt = $connect->stmt_init();
		if($stmt->prepare($sql_query)) {	
			// Bind your variables to replace the ?s
			$stmt->bind_param('s', $ID);
			// Execute query
			$stmt->execute();
			// store result 
			$stmt->store_result();
			$stmt->bind_result($data['nid'], 
					$data['news_heading'], 
					$data['cid'], 
					$data['news_status'], 
					$data['news_date'], 
					$data['news_image'],
					$data['news_description']
					);
			$stmt->fetch();
			$stmt->close();
		}
			
	?>

	<!-- START CONTENT -->
    <section id="content">

        <!--breadcrumbs start-->
        <div id="breadcrumbs-wrapper" class=" grey lighten-3">
          	<div class="container">
            	<div class="row">
              		<div class="col s12 m12 l12">
               			<h5 class="breadcrumbs-title">ویرایش مطلب</h5>
		                <ol class="breadcrumb">
		                  <li><a href="dashboard.php">داشبورد</a>
		                  </li>
		                  <li><a href="#" class="active">ویرایش مطلب</a>
		                  </li>
		                </ol>
              		</div>
            	</div>
          	</div>
        </div>
        <!--breadcrumbs end-->

         <!--start container-->
        <div class="container">
          	<div class="section">
				<div class="row">
		        	<div class="col s12 m12 l12">
		        		<div class="card-panel">
		                	<div class="row">
		                 		<form method="post" class="col s12" enctype="multipart/form-data">
		                  			<div class="row">
		                  			<?php echo isset($error['update_data']) ? $error['update_data'] : '';?> 
		                    			<div class="input-field col s4">							
											<div class="row">
						                      <div class="input-field col s12">
						                        <input type="text" name="news_heading" id="news_heading" value="<?php echo $data['news_heading']; ?>" required/>
						                        <label for="news_heading">عنوان اصلی مطلب</label><?php echo isset($error['news_heading']) ? $error['news_heading'] : '';?>
						                      </div>
						                    </div> 

											<div class="row">
						                      <div class="input-field col s12">
						                        <input type="text" name="news_date" value="<?php echo $data['news_date']; ?>" id="news_date" required/>
						                        <label for="news_date">عنوان فرعی</label><?php echo isset($error['news_date']) ? $error['news_date'] : '';?>
						                      </div>
						                    </div>  

						                    <div class="row">
							                    <div class="input-field col s12">
	                                            <select name="cid">
													<?php while($stmt_category->fetch()){ 
														if($category_data['cid'] == $data['cid']){?>
															<option value="<?php echo $category_data['cid']; ?>" selected="<?php echo $data['cid']; ?>" ><?php echo $category_data['category_name']; ?></option>
														<?php }else{ ?>
															<option value="<?php echo $category_data['cid']; ?>" ><?php echo $category_data['category_name']; ?></option>
														<?php }} ?>
	                                            </select>
	                                            <label>دسته بندی مطلب</label><?php echo isset($error['cid']) ? $error['cid'] : '';?>
	                                            </div>	
                                            </div>
                                        </div>    

                                        <div class="input-field col s8">    				                    	

						                    <div class="row">
						                      <div class="input-field col s12">
						                       <?php echo isset($error['news_description']) ? $error['news_description'] : '';?>
												<textarea name="news_description" id="news_description" class="materialize-textarea" rows="16"><?php echo $data['news_description']; ?></textarea>
												<script type="text/javascript" src="assets/js/ckeditor/ckeditor.js"></script>
												<script type="text/javascript">                        
										            CKEDITOR.replace( 'news_description' );
                                                                                                                                                                                            config.defaultLanguage = 'fa';
										        </script>		
											  </div>
						                    </div> 						                    			                    

						                    <br>
	                                        <button class="btn cyan waves-effect waves-light right"
	                                                type="submit" name="btnEdit">بروزرسانی
	                                            <i class="mdi-content-send right"></i>
	                                        </button>	                                        

										</div>
						            </div>
						        </form>
						    </div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>

<?php 
	$stmt_category->close();
	include_once('includes/close_database.php'); ?>