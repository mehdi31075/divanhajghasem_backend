<?php
	include_once('includes/connect_database.php'); 
	include_once('functions.php'); 
?>

<?php

	//Total category count
	$sql_category = "SELECT COUNT(*) as num FROM tbl_news_category";
	$total_category = mysqli_query($connect, $sql_category);
	$total_category = mysqli_fetch_array($total_category);
	$total_category = $total_category['num'];

	//Total news count
	// $news_query = "SELECT COUNT(*) as num FROM tbl_news";
	// $total_news = mysqli_fetch_array(mysqli_query($news_query));
	// $total_news = $total_news['num'];
	$sql_news = "SELECT COUNT(*) as num FROM tbl_news";
	$total_news = mysqli_query($connect, $sql_news);
	$total_news = mysqli_fetch_array($total_news);
	$total_news = $total_news['num'];

?>
        <!--breadcrumbs start-->
        <div id="breadcrumbs-wrapper" class=" grey lighten-3">
          <div class="container">
            <div class="row">
              <div class="col s12 m12 l12">
                <h5 class="breadcrumbs-title">داشبورد</h5>
                <ol class="breadcrumb">
                  <li><a href="dashboard.php">داشبورد</a>
                  </li>
                  <li><a href="#" class="active">صفحه اصلی</a>
                </ol>
              </div>
            </div>
          </div>
        </div>
        <!--breadcrumbs end-->

        <!--start container-->
        <div class="container">
            <div class="section">

                        <!--card stats start-->
            <div id="card-stats" class="seaction">
              <div class="row">
                            <div class="col s12 m6 l3">
                            <a href="category.php">
                                <div class="card">
                                    <div class="card-content green white-text">
                                        <p class="card-stats-title"><i class="mdi-social-group-add"></i> دسته بندی</p>
                                        <h4 class="card-stats-number"><?php echo $total_category;?></h4>
                                        <p class="card-stats-compare"><span class="green-text text-lighten-5">تعداد تمامی دسته بندی ها</span>
                                        </p>
                                    </div>
                                    <div class="card-action  green darken-2">
                                        <div id="clients-bar"></div>
                                    </div>
                                </div>
                            </a>
                            </div>

                            <div class="col s12 m6 l3">
                            <a href="story.php">
                                <div class="card">
                                    <div class="card-content purple white-text">
                                        <p class="card-stats-title"><i class="mdi-social-group-add"></i> لیست مطالب</p>
                                        <h4 class="card-stats-number"><?php echo $total_news;?></h4>
                                        <p class="card-stats-compare"><span class="purple-text text-lighten-5">تعداد تمامی مطالب</span>
                                        </p>
                                    </div>
                                    <div class="card-action  purple darken-2">
                                        <div id="clients-bar"></div>
                                    </div>
                                </div>
                            </a>
                            </div>

                          <!--   <div class="col s12 m6 l3">
                            <a href="https://console.firebase.google.com/" target="_blank">
                                <div class="card">
                                    <div class="card-content deep-purple white-text">
                                        <p class="card-stats-title"><i class="mdi-social-group-add"></i> پوش نوتیفیکیشن</p>
                                        <h4 class="card-stats-number"><i class="mdi-social-notifications"></i></h4>
                                        <p class="card-stats-compare"><span class="deep-purple-text text-lighten-5">ارسال نوتیفیکیشن توسط فایربیس</span>
                                        </p>
                                    </div>
                                    <div class="card-action  deep-purple darken-2">
                                        <div id="clients-bar"></div>
                                    </div>
                                </div>
                            </a>
                            </div>          -->                

                            <div class="col s12 m6 l3">
                            <a href="admin.php">
                                <div class="card">
                                    <div class="card-content blue-grey white-text">
                                        <p class="card-stats-title"><i class="mdi-social-group-add"></i> تنظیمات</p>
                                        <h4 class="card-stats-number"><i class="mdi-action-settings"></i></h4>
                                        <p class="card-stats-compare"><span class="blue-grey-text text-lighten-5">مدیریت تنظیمات</span>
                                        </p>
                                    </div>
                                    <div class="card-action  blue-grey darken-2">
                                        <div id="clients-bar"></div>
                                    </div>
                                </div>
                            </a>
                            </div>                           
                           
                        </div>
            </div>
            <!--card stats end-->
    </div>
</div> 

<br>
<br>
<br>
<br>
<br>
<br>
<br>
<br>
<br>
<?php include_once('includes/close_database.php'); ?>