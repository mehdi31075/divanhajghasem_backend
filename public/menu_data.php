<?php
	include_once('includes/connect_database.php');
?>

	<?php 
		if(isset($_GET['id'])){
			$ID = $_GET['id'];
		}else{
			$ID = "";
		}
		
		// create array variable to store data from database
		$data = array();
		
		// get all data from menu table and category table
		$sql_query = "SELECT nid, news_heading, news_date, news_status, category_name, news_image, news_description 
				FROM tbl_news m, tbl_news_category c
				WHERE m.nid = ? AND m.cat_id = c.cid";
		
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
					$data['news_date'], 
					$data['news_status'], 
					$data['category_name'],
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
               			<h5 class="breadcrumbs-title">مشاهده مطلب</h5>
		                <ol class="breadcrumb">
		                  <li><a href="dashboard.php">داشبورد</a>
		                  </li>
		                  <li><a href="#" class="active">مشاهده مطلب</a>
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
		                 		<div class="col s12">
		                  			<div class="row">
		                    			<div class="input-field col s12">  
											<form method="post">
												<table table class='bordered hoverable'>
													<tr class="row">
														<th class="detail" width="15%">عنوان اصلی مطلب</th>
														<td class="detail"><?php echo $data['news_heading']; ?></td>
													</tr>
														<tr class="row">
														<th class="detail">عنوان فرعی</th>
														<td class="detail"><?php echo $data['news_date']; ?></td>
													</tr>
													<tr class="row">
														<th class="detail">دسته بندی مطلب</th>
														<td class="detail"><?php echo $data['category_name']; ?></td>
													</tr>
													<tr class="row">
														<th class="detail">محتوا</th>
														<td class="detail"><?php echo $data['news_description']; ?></td>
													</tr>
												</table>
												
											</form>
											<br>
											<a href="edit-menu.php?id=<?php echo $ID; ?>"><button class="btn cyan waves-effect waves-light">ویرایش</button></a>
											<a href="delete-menu.php?id=<?php echo $ID; ?>"><button class="btn cyan waves-effect waves-light">حذف</button></a>		                    			

										</div>
									</div>
						        </div>
						    </div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>        

			
<?php include_once('includes/close_database.php'); ?>