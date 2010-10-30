<?php $view->extend('LichessBundle::layout.php') ?>
<?php $view['slots']->set('title', 'Lichess - '.$view['translator']->_('Play with the machine').' - '.$color) ?>

<div class="lichess_game_not_started waiting_opponent clearfix lichess_player_<?php echo $color ?>">
    <div class="lichess_board_wrap">
        <?php $view->output('LichessBundle:Main:staticBoard_'.$color.'.php') ?>
        <div class="lichess_overboard game_config">
            <h2><?php echo $view['translator']->_('Play with the machine') ?></h2>
            <div class="game_config_form">
                <form action="<?php echo $view['router']->generate('lichess_invite_ai', array('color' => $color)) ?>" method="post">
                    <div class="variants">
                    <?php foreach($form['variant'] as $variant): ?>
                        <?php echo $variant->widget() ?>
                    <?php endforeach ?>
                    </div>
                    <button type="submit" class="submit">Start</button>
                </form>
            </div>
        </div>
    </div>
    <?php $view->output('LichessBundle:Game:bootGround.php', array('color' => $color, 'active' => 'ai')) ?>
</div>
