<?php defined('C5_EXECUTE') or exit('No direct access allowed');

$action = $this->controller->getRequestAction();

if ($action === 'options') {
?>
    <form action="<?php echo $this->action('options'); ?>" method="post" class="form-horizontal">
        <?php echo $this->controller->token->output('slickplan-import')?>
        <fieldset>
            <legend>Website Settings</legend>
            <div class="checkbox">
                <label>
                    <input type="checkbox" name="slickplan_importer[settings_title]" value="1">
                    Set website title to „<?php echo $slickplan_title; ?>”
                </label>
                <small class="help-block">(It will change the Site Name in System & Settings &gt; Basic)</small>
            </div>
        </fieldset>
        <fieldset>
            <legend>Pages Titles</legend>
            <div class="radio">
                <label>
                    <input type="radio" name="slickplan_importer[titles_change]" value="" checked>
                    No change
                </label>
            </div>
            <div class="radio">
                <label>
                    <input type="radio" name="slickplan_importer[titles_change]" value="ucfirst">
                    Make just the first character uppercase
                </label>
                <small class="help-block">(This is an example page title)</small>
            </div>
            <div class="radio">
                <label>
                    <input type="radio" name="slickplan_importer[titles_change]" value="ucwords">
                    Uppercase the first character of each word
                </label>
                <small class="help-block">(This Is An Example Page Title)</small>
            </div>
        </fieldset>
        <fieldset>
            <legend> Pages Settings </legend>
            <div class="radio">
                <label>
                    <input type="radio" name="slickplan_importer[content]" value="contents" checked>
                    Import page content
                </label>
            </div>
            <div class="checkbox" style="padding-left: 20px;">
                <label>
                    <input type="checkbox" name="slickplan_importer[content_files]" value="1">
                    Import files to media library
                </label>
                <small class="help-block">(Downloading files may take a while)</small>
            </div>
            <div class="radio">
                <label>
                    <input type="radio" name="slickplan_importer[content]" value="desc">
                    Import page notes as content
                </label>
            </div>
            <div class="radio">
                <label>
                    <input type="radio" name="slickplan_importer[content]" value="">
                    Don’t import any content
                </label>
            </div>
        </fieldset>
        <?php if (isset($slickplan_xml['users']) and is_array($slickplan_xml['users']) and count($slickplan_xml['users'])) { ?>
            <fieldset>
                <legend>Users Mapping</legend>
                <?php
                foreach ($slickplan_xml['users'] as $user_id => $data) {
                    $name = array();
                    if (isset($data['firstName']) and $data['firstName']) {
                        $name[] = $data['firstName'];
                    }
                    if (isset($data['lastName']) and $data['lastName']) {
                        $name[] = $data['lastName'];
                    }
                    if (isset($data['email']) and $data['email']) {
                        if (count($name)) {
                            $data['email'] = '(' . $data['email'] . ')';
                        }
                        $name[] = $data['email'];
                    }
                    if (!count($name)) {
                        $name[] = $user_id;
                    }
                    ?>
                    <div style="padding: 2px 0;">
                        <?php echo implode(' ', $name); ?>:
                        <select class="form-control" name="slickplan_importer[users_map][<?php echo $user_id; ?>]" style="display: inline-block; width: 200px;">
                            <?php foreach ($slickplan_users as $user) { ?>
                                <option value="<?php echo $user->uID; ?>"><?php echo $user->uName; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                <?php } ?>
            </fieldset>
        <?php } ?>
        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <button class="pull-right btn btn-success" type="submit">Submit</button>
            </div>
        </div>
    </form>
<?php } elseif ($action === 'done' or $action === 'ajax') { ?>
    <form id="slickplan-importer">
        <h3><?php echo ($action === 'done') ? 'Success!' : 'Importing Pages&hellip;'; ?></h3>
        <div class="alert alert-success slickplan-show-summary" role="alert"<?php if ($action === 'ajax') { ?> style="display: none"<?php } ?>>
            <p>Pages have been imported. Thank you for using <a href="http://slickplan.com/" target="_blank">Slickplan</a> Importer.</p>
        </div>
        <?php if ($action === 'ajax') { ?>
            <div id="slickplan-progressbar" class="progressbar"><div class="ui-progressbar-value"><div class="progress-label">0%</div></div></div>
        <?php } ?>
        <p><hr></p>
        <div class="slickplan-summary"><?php if ($action === 'done') echo $slickplan_xml['summary']; ?></div>
        <p><hr></p>
        <p class="slickplan-show-summary"<?php if ($action === 'ajax') { ?> style="display: none"<?php } ?>>
            <a class="btn btn-default" href="/index.php/dashboard/sitemap/full" role="button">See all pages</a>
        </p>
    </form>
    <style type="text/css">
        #slickplan-progressbar {
            position: relative;
            background: #fff;
            border: 1px solid #aaa;
            color: #222;
            height: 29px;
        }
        #slickplan-progressbar .ui-progressbar-value {
            background: #ccc;
            color: #222;
            font-weight: bold;
            height: 100%;
            width: 0;
            -webkit-transition: width .2s ease;
            -moz-transition: width .2s ease;
            transition: width .2s ease;
        }
        #slickplan-progressbar .progress-label {
            position: absolute;
            left: 49%;
            top: 5px;
            font-weight: bold;
            text-shadow: 1px 1px 0 #fff;
        }
    </style>
    <?php if ($action === 'ajax') { ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var SLICKPLAN_JSON = <?php echo json_encode($slickplan_xml['sitemap']); ?>;
                var SLICKPLAN_HTML = '<?php echo addslashes($slickplan_html); ?>';

                var $form = $('form#slickplan-importer');
                var $summary = $form.find('.slickplan-summary');
                var $progress = $('#slickplan-progressbar');

                var _pages = [];
                var _importIndex = 0;

                var _generatePagesFlatArray = function(pages, parent) {
                    $.each(pages, function(index, data) {
                        if (data.id) {
                            _pages.push({
                                id: data.id,
                                parent: parent,
                                title: data.title
                            });
                            if (data.childs) {
                                _generatePagesFlatArray(data.childs, data.id);
                            }
                        }
                    });
                };

                var _addMenuID = function(parent_id, mlid) {
                    for (var i = 0; i < _pages.length; ++i) {
                        if (_pages[i].parent === parent_id) {
                            _pages[i].mlid = mlid;
                        }
                    }
                };

                var _importPage = function(page) {
                    var html = SLICKPLAN_HTML.replace('{title}', page.title);
                    var $element = $(html).appendTo($summary);
                    var percent = Math.round((_importIndex / _pages.length) * 100) + '%';
                    $progress
                        .find('.ui-progressbar-value').width(percent).end()
                        .find('.progress-label').text(percent);
                    $.post(window.location, {
                        slickplan: {
                            page: page.id,
                            parent: page.parent ? page.parent : '',
                            mlid: page.mlid ? page.mlid : 0,
                            last: (_pages && _pages[_importIndex + 1]) ? 0 : 1
                        }
                    }, function(data) {
                        if (data && data.html) {
                            $element.replaceWith(data.html);
                            ++_importIndex;
                            if (data) {
                                if (data.mlid) {
                                    _addMenuID(page.id, data.mlid);
                                }
                            }
                            if (_pages && _pages[_importIndex]) {
                                _importPage(_pages[_importIndex]);
                            } else {
                                var percent = '100%';
                                $progress
                                    .find('.ui-progressbar-value').width(percent).end()
                                    .find('.progress-label').text(percent);
                                $form.find('h3').text('Success!');
                                $form.find('.slickplan-show-summary').show();
                                $(window).scrollTop(0);
                                setTimeout(function() {
                                    $progress.remove();
                                }, 500);
                            }
                        }
                    }, 'json');
                };

                var types = ['home', '1', 'util', 'foot'];
                for (var i = 0; i < types.length; ++i) {
                    if (SLICKPLAN_JSON[types[i]] && SLICKPLAN_JSON[types[i]].length) {
                        _generatePagesFlatArray(SLICKPLAN_JSON[types[i]]);
                    }
                }

                $(window).load(function() {
                    _importIndex = 0;
                    if (_pages && _pages[_importIndex]) {
                        $(window).scrollTop(0);
                        _importPage(_pages[_importIndex]);
                    }
                });
            });
        </script>
    <?php } ?>
<?php } else { ?>
    <form action="<?php echo $this->action('import'); ?>" method="post" class="form-horizontal" enctype="multipart/form-data">
        <?php echo $this->controller->token->output('slickplan-import')?>
        <fieldset>
            <legend>Select XML file to import</legend>
            <div class="alert alert-info" role="alert">
                <p>This importer allows you to import pages structure from a Slickplan’s XML file into your WordPress site.</p>
                <p>Pick a XML file to upload and click Import.</p>
            </div>
            <div class="control-group">
                <label for="slickplan-importer-file">Choose a file from your computer:</label>
                <div class="controls">
                    <input type="file" id="slickplan-importer-file" name="slickplan_file">
                </div>
            </div>
        </fieldset>
        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <button class="pull-right btn btn-success" type="submit">Import</button>
            </div>
        </div>
    </form>
<?php
}