<?php
/**
 * 站內付 2.0 結果／提示頁（v0.5.0）——付款頁無法開啟、3D 驗證後失敗或結果待確認時顯示。
 *
 * @var array<string,mixed> $ecpg
 */
defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $ecpg['site_name'] ); ?> — <?php echo esc_html( $ecpg['title'] ); ?></title>
	<style>
		* { box-sizing: border-box; }
		body { margin: 0; background: #f4f5f7; color: #1f2933; font: 15px/1.6 -apple-system, "Segoe UI", "Noto Sans TC", "PingFang TC", sans-serif; }
		.ys-ecpg { max-width: 560px; margin: 48px auto; padding: 0 16px; }
		.ys-ecpg__card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(16, 24, 40, .08); padding: 28px 24px; text-align: center; }
		h1 { font-size: 20px; margin: 0 0 12px; }
		p { margin: 0 0 20px; color: #52606d; }
		.ys-ecpg__btn { display: inline-block; text-decoration: none; border-radius: 8px; padding: 12px 20px; font-weight: 600; background: #1f6feb; color: #fff; }
	</style>
</head>
<body>
<div class="ys-ecpg">
	<div class="ys-ecpg__card">
		<h1><?php echo esc_html( $ecpg['title'] ); ?></h1>
		<p><?php echo esc_html( $ecpg['message'] ); ?></p>
		<a class="ys-ecpg__btn" href="<?php echo esc_url( $ecpg['action_url'] ); ?>"><?php echo esc_html( $ecpg['action_label'] ); ?></a>
	</div>
</div>
</body>
</html>
