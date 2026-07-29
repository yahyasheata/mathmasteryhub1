<footer class="pt-5 ds-surface ds-border-top">

<div class="container pb-12 text-center pt-12">
    <div class="row mt-n10 mt-lg-0">
        <div class="col-xl-10 mx-auto">
            <div class="row mb-3 d-flex">
                <div class="col-md-6 mb-3">
                    <div class="widget">
                        <img src="<?=rtrim((string)$baseUrl, '/')?>/<?=$site_settings['website_logo']?>" style="width:160px;max-width:100%" class="mb-3 site-logo mathhub-logo mathhub-logo--light">
                        <img src="<?=rtrim((string)$baseUrl, '/')?>/resources/images/branding/mathhub-logo-white.png" style="width:160px;max-width:100%" class="mb-3 site-logo mathhub-logo mathhub-logo--dark">
                        <div style="text-align:justify;"><?=$site_settings['website_bio']?></div>
                    </div>
                    <!-- /.widget -->
                </div>

                <div class="col-md-3 mb-3">
                    <div class="widget">
                        <div class="widget-title display-6 mb-5">Links</div>


                        <div><a href="<?=$baseUrl?>" class="link-body"><span
                                    class="fas fa-home font-1 d-none text-info"
                                    style="width: 15px"></span> Home</a></div>
                        <div><a href="<?= $baseUrl ?>/resources/blog" class="link-body"><span
                                    class="fas fa-pen-alt font-1 d-none text-info"
                                    style="width: 15px"></span> Blog</a></div>
                        <div><a href="<?= $baseUrl ?>/resources/page/terms" class="link-body"><span
                                    class="fas fa-lock font-1 d-none text-info"
                                    style="width: 15px"></span> Terms of Use</a></div>
                        <div><a href="<?= $baseUrl ?>/resources/contact" class="link-body"><span
                                    class="fas fa-phone font-1 d-none text-info"
                                    style="width: 15px"></span> Contact Us</a></div>


                    </div>
                    <!-- /.widget -->
                </div>

                <div class="col-md-3 mb-3">
                    <div class="widget">
                        <div class="widget-title display-6 mb-5">Follow Us</div>

                        <nav class="nav social">
                            <a href="<?= $baseUrl ?>/resources/admin/settings"><i
                                    class="fab fa-twitter"></i></a>
                            <a href="<?= $baseUrl ?>/resources/admin/settings"><i
                                    class="fab fa-facebook-f"></i></a>
                            <a href="<?= $baseUrl ?>/resources/admin/settings"><i
                                    class="fab fa-instagram"></i></a>
                            <a href="<?= $baseUrl ?>/resources/admin/settings"><i
                                    class="fab fa-youtube"></i></a>
                        </nav>


                    </div>
                    <!-- /.widget -->
                </div>

                <!--/column -->
            </div>
            <!--/.row -->


            <!-- /.social -->
        </div>
        <!-- /column -->
    </div>
    <!-- /.row -->
</div>
<!-- /.container -->
</footer>
