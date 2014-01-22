<div class="wrap">
    <h2>Brafton Importer</h2>
    <form method="post" action="options.php"> 
        <?php @settings_fields('BraftonImporter-group'); ?>
        <?php @do_settings_fields('BraftonImporter-group'); ?>

        <?php do_settings_sections('BraftonImporter'); ?>

        <?php @submit_button(); ?>
    </form>
</div>