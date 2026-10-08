<?php
/**
 * Plugin Name: Contador de Visualizações
 * Description: Conta as visualizações de páginas específicas e exibe o total em qualquer página via shortcode, sem exigir login.
 * Version:     1.3.0
 * Author:      Douglas
 * Text Domain: contador-visualizacoes
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CV_Contador_Visualizacoes {

	const META_KEY   = '_cv_views';
	const OPTION_KEY = 'cv_options';
	const VERSION    = '1.3.0';

	/** @var bool Evita enfileirar o script mais de uma vez. */
	private static $script_enfileirado = false;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'registrar_rotas' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enfileirar_na_pagina_monitorada' ] );
		add_shortcode( 'contador_visualizacoes', [ __CLASS__, 'shortcode' ] );

		// Admin
		add_action( 'admin_menu', [ __CLASS__, 'menu_admin' ] );
		add_action( 'admin_init', [ __CLASS__, 'registrar_opcoes' ] );
		add_filter( 'manage_pages_columns', [ __CLASS__, 'coluna_titulo' ] );
		add_action( 'manage_pages_custom_column', [ __CLASS__, 'coluna_conteudo' ], 10, 2 );
	}

	/* ---------------------------------------------------------------
	 * Opções
	 * ------------------------------------------------------------- */

	private static function opcoes() {
		$padrao = [
			'paginas'       => [],  // IDs das páginas monitoradas
			'ignorar_admin' => 1,   // não contar usuários logados que podem editar
			'ignorar_bots'  => 1,   // não contar bots/crawlers
			'tempo_minutos' => 30,  // anti-inflação: intervalo mínimo entre contagens do mesmo visitante (0 = desativado)
			'texto_padrao'  => '{n} visualizações', // texto exibido com o número
			'texto_carregando' => 'Carregando...',  // texto exibido enquanto a contagem é buscada
		];
		return wp_parse_args( get_option( self::OPTION_KEY, [] ), $padrao );
	}

	private static function pagina_monitorada( $post_id ) {
		$opcoes = self::opcoes();
		return in_array( (int) $post_id, array_map( 'intval', (array) $opcoes['paginas'] ), true );
	}

	/* ---------------------------------------------------------------
	 * REST API (pública, não exige login)
	 *  - POST /cv/v1/view  : registra a visita e devolve o total
	 *  - GET  /cv/v1/views : apenas devolve o total
	 * ------------------------------------------------------------- */

	public static function registrar_rotas() {
		$args = [
			'post_id' => [
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];

		register_rest_route( 'cv/v1', '/view', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'registrar_visualizacao' ],
			'permission_callback' => '__return_true',
			'args'                => $args,
		] );

		register_rest_route( 'cv/v1', '/views', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'ler_visualizacoes' ],
			'permission_callback' => '__return_true',
			'args'                => $args,
		] );
	}

	/** Monta a resposta padrão, sempre sem cache. */
	private static function resposta( $post_id, $contou ) {
		$total    = self::obter_total( $post_id );
		$resposta = new WP_REST_Response( [
			'counted'   => (bool) $contou,
			'views'     => $total,
			'formatted' => number_format_i18n( $total ),
		], 200 );
		$resposta->header( 'Cache-Control', 'no-store, max-age=0' );
		return $resposta;
	}

	public static function ler_visualizacoes( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return new WP_Error( 'cv_post_invalido', 'Página inválida.', [ 'status' => 404 ] );
		}

		return self::resposta( $post_id, false );
	}

	public static function registrar_visualizacao( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$opcoes  = self::opcoes();

		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return new WP_Error( 'cv_post_invalido', 'Página inválida.', [ 'status' => 404 ] );
		}

		if ( ! self::pagina_monitorada( $post_id ) ) {
			return self::resposta( $post_id, false );
		}

		if ( $opcoes['ignorar_admin'] && is_user_logged_in() && current_user_can( 'edit_pages' ) ) {
			return self::resposta( $post_id, false );
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( $opcoes['ignorar_bots'] && ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|curl|wget|python-requests/i', $ua ) ) ) {
			return self::resposta( $post_id, false );
		}

		// Anti-inflação: evita contar recarregamentos repetidos do mesmo visitante
		// dentro do intervalo configurado (0 = sempre conta).
		$tempo = absint( $opcoes['tempo_minutos'] );
		if ( $tempo > 0 ) {
			$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$transient = 'cv_' . md5( $ip . '|' . $ua . '|' . $post_id );
			if ( get_transient( $transient ) ) {
				return self::resposta( $post_id, false );
			}
			set_transient( $transient, 1, $tempo * MINUTE_IN_SECONDS );
		}

		self::incrementar( $post_id );

		return self::resposta( $post_id, true );
	}

	private static function incrementar( $post_id ) {
		global $wpdb;

		// Garante que o meta existe e depois incrementa de forma atômica.
		add_post_meta( $post_id, self::META_KEY, 0, true );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta}
				 SET meta_value = meta_value + 1
				 WHERE post_id = %d AND meta_key = %s",
				$post_id,
				self::META_KEY
			)
		);

		wp_cache_delete( $post_id, 'post_meta' );
	}

	private static function obter_total( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_KEY, true );
	}

	/* ---------------------------------------------------------------
	 * Front-end: script (registra a visita e/ou atualiza os contadores)
	 * ------------------------------------------------------------- */

	/** Em páginas monitoradas, o script também registra a visita. */
	public static function enfileirar_na_pagina_monitorada() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( $post_id && self::pagina_monitorada( $post_id ) ) {
			self::enfileirar_script( $post_id );
		}
	}

	/**
	 * @param int $contar_post_id ID da página cuja visita deve ser registrada
	 *                            (0 = apenas atualizar os contadores exibidos).
	 */
	private static function enfileirar_script( $contar_post_id = 0 ) {
		if ( self::$script_enfileirado ) {
			return;
		}
		self::$script_enfileirado = true;

		wp_register_script( 'cv-contador', '', [], self::VERSION, true );
		wp_enqueue_script( 'cv-contador' );

		$dados = [
			'urlContar' => esc_url_raw( rest_url( 'cv/v1/view' ) ),
			'urlLer'    => esc_url_raw( rest_url( 'cv/v1/views' ) ),
			'countId'   => (int) $contar_post_id,
		];

		$js = <<<'JS'
(function () {
	var cfg = __DADOS__;

	// Troca a mensagem "Carregando..." pelo conteúdo com o número.
	function mostrar(el) {
		var carregando = el.querySelector('.cv-carregando');
		var conteudo = el.querySelector('.cv-conteudo');
		if (carregando) { carregando.hidden = true; }
		if (conteudo) { conteudo.hidden = false; }
		el.setAttribute('data-estado', 'pronto');
	}

	function iniciar() {
		var elementos = document.querySelectorAll('.cv-contador');
		var grupos = {};

		Array.prototype.forEach.call(elementos, function (el) {
			var id = el.getAttribute('data-post');
			(grupos[id] = grupos[id] || []).push(el);
		});

		// Página monitorada sem shortcode: registra a visita mesmo assim.
		if (cfg.countId && !grupos[cfg.countId]) {
			grupos[cfg.countId] = [];
		}

		Object.keys(grupos).forEach(function (id) {
			var contar = String(cfg.countId) === id;
			var url = contar ? cfg.urlContar : cfg.urlLer + '?post_id=' + encodeURIComponent(id);
			var opcoes = contar
				? {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ post_id: parseInt(id, 10) }),
					credentials: 'same-origin'
				}
				: { method: 'GET', cache: 'no-store', credentials: 'same-origin' };

			fetch(url, opcoes)
				.then(function (r) { return r.json(); })
				.then(function (r) {
					grupos[id].forEach(function (el) {
						if (r && typeof r.formatted !== 'undefined') {
							var n = el.querySelector('.cv-numero');
							if (n) { n.textContent = r.formatted; }
						}
						mostrar(el);
					});
				})
				.catch(function () {
					// Em caso de erro, exibe o valor que veio do servidor.
					grupos[id].forEach(mostrar);
				});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', iniciar);
	} else {
		iniciar();
	}
})();
JS;

		$js = str_replace( '__DADOS__', wp_json_encode( $dados ), $js );
		wp_add_inline_script( 'cv-contador', $js );
	}

	/* ---------------------------------------------------------------
	 * Shortcode:
	 * [contador_visualizacoes id="123" texto="{n} visualizações" carregando="Carregando..."]
	 * Funciona para qualquer visitante, logado ou não.
	 * ------------------------------------------------------------- */

	public static function shortcode( $atts ) {
		$opcoes = self::opcoes();
		$atts   = shortcode_atts( [
			'id'         => 0,
			'texto'      => $opcoes['texto_padrao'],
			'carregando' => $opcoes['texto_carregando'],
		], $atts, 'contador_visualizacoes' );

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return '';
		}

		// Garante que o script esteja presente em qualquer página que use o shortcode.
		self::enfileirar_script( 0 );

		$total = self::obter_total( $post_id );

		// Escapa cada trecho do texto e injeta o número dentro de um <span>.
		$partes = array_map( 'esc_html', explode( '{n}', $atts['texto'] ) );
		$numero = '<span class="cv-numero">' . esc_html( number_format_i18n( $total ) ) . '</span>';

		// Sem JavaScript, mostra direto o valor salvo em vez do "Carregando...".
		$noscript = '<noscript><style>.cv-contador .cv-carregando{display:none!important}.cv-contador .cv-conteudo[hidden]{display:inline!important}</style></noscript>';

		return sprintf(
			'<span class="cv-contador" data-post="%1$d" data-estado="carregando" aria-live="polite">'
			. '<span class="cv-carregando">%2$s</span>'
			. '<span class="cv-conteudo" hidden>%3$s</span>'
			. '%4$s'
			. '</span>',
			$post_id,
			esc_html( $atts['carregando'] ),
			implode( $numero, $partes ),
			$noscript
		);
	}

	/* ---------------------------------------------------------------
	 * Admin: configurações e coluna na lista de páginas
	 * ------------------------------------------------------------- */

	public static function menu_admin() {
		add_options_page(
			'Contador de Visualizações',
			'Contador de Visualizações',
			'manage_options',
			'cv-contador',
			[ __CLASS__, 'pagina_opcoes' ]
		);
	}

	public static function registrar_opcoes() {
		register_setting( 'cv_grupo', self::OPTION_KEY, [
			'sanitize_callback' => [ __CLASS__, 'sanitizar_opcoes' ],
		] );
	}

	public static function sanitizar_opcoes( $entrada ) {
		$padrao = [
			'texto_padrao'     => '{n} visualizações',
			'texto_carregando' => 'Carregando...',
		];

		$texto = isset( $entrada['texto_padrao'] ) ? sanitize_text_field( $entrada['texto_padrao'] ) : '';
		if ( '' === $texto || false === strpos( $texto, '{n}' ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'cv_texto_invalido',
				'O texto de exibição precisa conter {n}, que é substituído pelo número. O valor padrão foi restaurado.',
				'warning'
			);
			$texto = $padrao['texto_padrao'];
		}

		$carregando = isset( $entrada['texto_carregando'] ) ? sanitize_text_field( $entrada['texto_carregando'] ) : '';
		if ( '' === $carregando ) {
			$carregando = $padrao['texto_carregando'];
		}

		return [
			'texto_padrao'     => $texto,
			'texto_carregando' => $carregando,
			'paginas'       => isset( $entrada['paginas'] ) ? array_values( array_filter( array_map( 'absint', (array) $entrada['paginas'] ) ) ) : [],
			'ignorar_admin' => empty( $entrada['ignorar_admin'] ) ? 0 : 1,
			'ignorar_bots'  => empty( $entrada['ignorar_bots'] ) ? 0 : 1,
			'tempo_minutos' => isset( $entrada['tempo_minutos'] ) ? min( 10080, absint( $entrada['tempo_minutos'] ) ) : 30,
		];
	}

	public static function pagina_opcoes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opcoes  = self::opcoes();
		$paginas = get_pages( [ 'post_status' => 'publish' ] );
		?>
		<div class="wrap">
			<h1>Contador de Visualizações</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'cv_grupo' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cv_paginas">Páginas monitoradas</label></th>
						<td>
							<select id="cv_paginas" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[paginas][]" multiple size="8" style="min-width:300px;">
								<?php foreach ( $paginas as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( in_array( $p->ID, array_map( 'intval', $opcoes['paginas'] ), true ) ); ?>>
										<?php echo esc_html( $p->post_title . ' (ID ' . $p->ID . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Segure Ctrl/Cmd para selecionar mais de uma. Apenas estas páginas terão as visualizações contadas.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Ignorar editores logados</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ignorar_admin]" value="1" <?php checked( $opcoes['ignorar_admin'], 1 ); ?>> Não contar visitas de quem pode editar páginas</label></td>
					</tr>
					<tr>
						<th scope="row">Ignorar bots</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ignorar_bots]" value="1" <?php checked( $opcoes['ignorar_bots'], 1 ); ?>> Não contar crawlers e robôs conhecidos</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="cv_tempo">Anti-inflação (minutos)</label></th>
						<td>
							<input type="number" id="cv_tempo" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tempo_minutos]" value="<?php echo esc_attr( absint( $opcoes['tempo_minutos'] ) ); ?>" min="0" max="10080" step="1" class="small-text">
							<p class="description">Tempo mínimo entre duas contagens do mesmo visitante (IP + navegador) na mesma página. Use <code>0</code> para contar todas as visitas, inclusive recarregamentos. Máximo: 10080 (7 dias).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cv_texto">Texto de exibição</label></th>
						<td>
							<input type="text" id="cv_texto" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[texto_padrao]" value="<?php echo esc_attr( $opcoes['texto_padrao'] ); ?>" class="regular-text">
							<p class="description">Use <code>{n}</code> no lugar do número. Exemplo: <code>{n} visualizações</code> ou <code>Visto {n} vezes</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cv_carregando">Texto de carregamento</label></th>
						<td>
							<input type="text" id="cv_carregando" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[texto_carregando]" value="<?php echo esc_attr( $opcoes['texto_carregando'] ); ?>" class="regular-text">
							<p class="description">Exibido enquanto a contagem é buscada. Exemplo: <code>Carregando...</code></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Como usar</h2>
			<p>Em qualquer página ou post, adicione o shortcode:</p>
			<p><code>[contador_visualizacoes id="123"]</code></p>
			<p>Sem o <code>id</code>, exibe a contagem da página atual.</p>
			<p>Atributos opcionais:</p>
			<ul style="list-style:disc;margin-left:20px;">
				<li><code>texto</code>: substitui o texto de exibição definido acima só nesse shortcode. Ex.: <code>texto="Esta página foi vista {n} vezes"</code></li>
				<li><code>carregando</code>: substitui o texto de carregamento definido acima só nesse shortcode. Ex.: <code>carregando="Buscando..."</code></li>
			</ul>
		</div>
		<?php
	}

	public static function coluna_titulo( $colunas ) {
		$colunas['cv_views'] = 'Visualizações';
		return $colunas;
	}

	public static function coluna_conteudo( $coluna, $post_id ) {
		if ( 'cv_views' === $coluna ) {
			echo self::pagina_monitorada( $post_id )
				? esc_html( number_format_i18n( self::obter_total( $post_id ) ) )
				: '&mdash;';
		}
	}
}

CV_Contador_Visualizacoes::init();
