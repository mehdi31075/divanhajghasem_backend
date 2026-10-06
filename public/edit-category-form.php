<?php
	include_once('includes/connect_database.php'); 
	include_once('functions.php'); 
?>

	<?php 
		if(isset($_GET['id'])){
			$ID = $_GET['id'];
		}else{
			$ID = "";
		}
		
		// create array variable to store category data
		$category_data = array();
			
		$sql_query = "SELECT category_image 
				FROM tbl_news_category 
				WHERE cid = ?";
				
		$stmt_category = $connect->stmt_init();
		if($stmt_category->prepare($sql_query)) {	
			// Bind your variables to replace the ?s
			$stmt_category->bind_param('s', $ID);
			// Execute query
			$stmt_category->execute();
			// store result 
			$stmt_category->store_result();
			$stmt_category->bind_result($previous_category_image);
			$stmt_category->fetch();
			$stmt_category->close();
		}
		
			
		if(isset($_POST['btnEdit'])){
			$category_name = $_POST['category_name'];
			$author = $_POST['author'];
			
			// get image info
			$menu_image = $_FILES['category_image']['name'];
			$image_error = $_FILES['category_image']['error'];
			$image_type = $_FILES['category_image']['type'];
				
			// create array variable to handle error
			$error = array();
				
			if(empty($category_name)){
				$error['category_name'] = " <span class='label label-danger'>Must Insert!</span>";
			}			

			if(empty($author)){
				$error['author'] = " <span class='label label-danger'>Must Insert!</span>";
			}
			
			// common image file extensions
			$allowedExts = array("gif", "jpeg", "jpg", "png");
			
			// get image file extension
			error_reporting(E_ERROR | E_PARSE);
			$extension = end(explode(".", $_FILES["category_image"]["name"]));
			
			if(!empty($menu_image)){
				if(!(($image_type == "image/gif") || 
					($image_type == "image/jpeg") || 
					($image_type == "image/jpg") || 
					($image_type == "image/x-png") ||
					($image_type == "image/png") || 
					($image_type == "image/pjpeg")) &&
					!(in_array($extension, $allowedExts))){
					
					$error['category_image'] = " <span class='label label-danger'>Image type must jpg, jpeg, gif, or png!</span>";
				}
			}
				
			if(!empty($category_name) && !empty($author) && empty($error['category_image'])){
					
				if(!empty($menu_image)){
					
					// create random image file name
					$string = '0123456789';
					$file = preg_replace("/\s+/", "_", $_FILES['category_image']['name']);
					$function = new functions;
					$category_image = $function->get_random_string($string, 4)."-".date("Y-m-d").".".$extension;
				
					// delete previous image
					$delete = unlink('upload/category/'."$previous_category_image");
					
					// upload new image
					$upload = move_uploaded_file($_FILES['category_image']['tmp_name'], 'upload/category/'.$category_image);
	  
					$sql_query = "UPDATE tbl_news_category 
							SET category_name = ?, author = ?, category_image = ?
							WHERE cid = ?";
							
					$upload_image = $category_image;
					$stmt = $connect->stmt_init();
					if($stmt->prepare($sql_query)) {	
						// Bind your variables to replace the ?s
						$stmt->bind_param('ssss', 
									$category_name, 
									$author, 
									$upload_image,
									$ID);
						// Execute query
						$stmt->execute();
						// store result 
						$update_result = $stmt->store_result();
						$stmt->close();
					}
				} else {
					
					$sql_query = "UPDATE tbl_news_category 
							SET category_name = ?, author = ?
							WHERE cid = ?";
					
					$stmt = $connect->stmt_init();
					if($stmt->prepare($sql_query)) {	
						// Bind your variables to replace the ?s
						$stmt->bind_param('sss', 
									$category_name, 
									$author, 
									$ID);
						// Execute query
						$stmt->execute();
						// store result 
						$update_result = $stmt->store_result();
						$stmt->close();
					}
				}
				
				// check update result
				if($update_result){
					$error['update_category'] = "<div class='card-panel teal lighten-2'>
												    <span class='white-text text-darken-2'>
													    با موفقیت آپدیت شد
												    </span>
												</div>";
				}else{
					$error['update_category'] = "<div class='card-panel red darken-1'>
												    <span class='white-text text-darken-2'>
													    Update Failed
												    </span>
												</div>";
				}
			}
				
		}
			
		// create array variable to store previous data
		$data = array();
		
		$sql_query = "SELECT * 
				FROM tbl_news_category 
				WHERE cid = ?";
		
		$stmt = $connect->stmt_init();
		if($stmt->prepare($sql_query)) {	
			// Bind your variables to replace the ?s
			$stmt->bind_param('s', $ID);
			// Execute query
			$stmt->execute();
			// store result 
			$stmt->store_result();
			$stmt->bind_result($data['cid'], 
					$data['category_name'],
					$data['category_image'],
					$data['author'],
					$data['status']
					);
			$stmt->fetch();
			$stmt->close();
		}

		if(isset($_POST['btnCancel'])){
			header("location: category.php");
		}
		
	?>

	<!-- START CONTENT -->
    <section id="content">

        <!--breadcrumbs start-->
        <div id="breadcrumbs-wrapper" class=" grey lighten-3">
          	<div class="container">
            	<div class="row">
              		<div class="col s12 m12 l12">
               			<h5 class="breadcrumbs-title">ویرایش دسته بندی</h5>
		                <ol class="breadcrumb">
		                  <li><a href="dashboard.php">داشبورد</a>
		                  </li>
		                  <li><a href="#" class="active">ویرایش دسته بندی</a>
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
		                    			<div class="input-field col s12">   
		                    				<?php echo isset($error['update_category']) ? $error['update_category'] : '';?>  
											
											<div class="row">
						                      <div class="input-field col s12">
						                        <input type="text" name="category_name" id="category_name" value="<?php echo $data['category_name']; ?>" required/>
						                        <label for="category_name">نام دسته بندی</label><?php echo isset($error['category_name']) ? $error['category_name'] : '';?>
						                      </div>
						                    </div> 

											<div class="row">
						                      <div class="input-field col s12">
						                        <input type="text" name="author" id="author" value="<?php echo $data['author']; ?>" required/>
						                        <label for="category_name">نام نویسنده</label><?php echo isset($error['author']) ? $error['author'] : '';?>
						                      </div>
						                    </div> 

											<img src="upload/category/<?php echo $data['category_image']; ?>" width="120" height="170"/>
											<div class="file-field input-field col s12">
											<?php echo isset($error['category_image']) ? $error['category_image'] : '';?>
	                                            <input class="file-path validate" type="text" disabled/>
	                                            <div class="btn">
	                                                <span>انتخاب تصویر</span>
	                                                <input type="file" name="category_image" id="category_image" value="" />
	                                            </div>	                                            
	                                        </div>	                                        

	                                        <button class="btn cyan waves-effect waves-light right"
	                                                type="submit" name="btnEdit">بروز رسانی
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
	
<?php include_once('includes/close_database.php'); ?>