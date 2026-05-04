jQuery( document ).ready( function ( $ ) {
	if ( typeof wp === 'undefined' || !wp.media ) return;

	var Image_Hunter_View = wp.media.View.extend( {
		tagName:   'div',
		className: 'cyphex_image_hunter_view_container',
		template:  wp.template( 'cyphex-image-hunter-search-panel' ),

		events: {
			'click #cyphex_image_hunter_search_btn': 'search',
			'keydown #cyphex_image_hunter_search_input': 'searchOnEnter',
			'click .cyphex_image_hunter_action_download': 'sideloadImage',
			'click .cyphex_image_hunter_action_refine': 'refineImage',
			'click .cyphex_image_hunter_action_remove_bg': 'removeBackground',
			'click .cyphex_image_hunter_action_inpaint': 'inpaintImage',
			'keydown': 'stopPropagation'
		},

		initialize: function ( options ) {
			// Inherit controller
			this.controller = options.controller;
			// Init persistent state if not exists
			if ( ! this.controller.cyphex_image_hunter_state ) {
				this.controller.cyphex_image_hunter_state = {
					prompt: '',
					source: 'puter-flux',
					width: '',
					height: '',
					resultsHTML: ''
				};
			}
			wp.media.View.prototype.initialize.apply( this, arguments );
		},

		stopPropagation: function ( e ) {
			// Prevent Ctrl+Z (Undo) from bubbling to the parent editor, which crashes the modal
			if ( ( e.ctrlKey || e.metaKey ) && e.keyCode === 90 ) {
				if ( ! jQuery( e.target ).is( 'input, textarea' ) ) {
					e.stopPropagation();
					e.preventDefault();
				}
			}
		},

		render: function () {
			try {
				this.$el.html( this.template() );
				
				// Restore State
				var state = this.controller.cyphex_image_hunter_state;
				if ( state ) {
					this.$( '#cyphex_image_hunter_search_input' ).val( state.prompt );
					this.$( '#cyphex_image_hunter_source' ).val( state.source );
					if ( state.width ) this.$( '#cyphex_image_hunter_width' ).val( state.width );
					if ( state.height ) this.$( '#cyphex_image_hunter_height' ).val( state.height );
					
					// Restore results if we have them
					if ( state.resultsHTML ) {
						this.$( '#cyphex_image_hunter_results_list' ).html( state.resultsHTML );
					}
				}
			} catch ( e ) {
				console.error( 'Cyphex: Render failed', e );
			}
			return this;
		},

		searchOnEnter: function ( e ) { if ( e.keyCode === 13 ) this.search( e ); },

		search: function ( e ) {
			e.preventDefault();
			var prompt = this.$( '#cyphex_image_hunter_search_input' ).val();
			var sourceVal = this.$( '#cyphex_image_hunter_source' ).val();
			var optimize = this.$( '#cyphex_image_hunter_optimize' ).is( ':checked' ) ? 1 : 0;
			var width = parseInt( this.$( '#cyphex_image_hunter_width' ).val() ) || 0;
			var height = parseInt( this.$( '#cyphex_image_hunter_height' ).val() ) || 0;
			
			if ( !prompt ) return;

			// Save basics to state
			this.controller.cyphex_image_hunter_state.prompt = prompt;
			this.controller.cyphex_image_hunter_state.source = sourceVal;
			this.controller.cyphex_image_hunter_state.width = this.$( '#cyphex_image_hunter_width' ).val();
			this.controller.cyphex_image_hunter_state.height = this.$( '#cyphex_image_hunter_height' ).val();

			this.currentPrompt = prompt;
			this.runSearch( prompt, sourceVal, optimize, width, height );
		},

		runSearch: function ( prompt, sourceVal, optimize, w, h ) {
			var self = this;
			var $status = this.$( '#cyphex_image_hunter_status' );
			var $aiFeedback = this.$( '#cyphex_image_hunter_ai_feedback' );
			var $list = this.$( '#cyphex_image_hunter_results_list' );
			var $btn = this.$( '#cyphex_image_hunter_search_btn' ); 
			
			var loadingText = optimize ? cyphex_image_hunter_vars.labels.statusOptimizing : cyphex_image_hunter_vars.labels.statusSearching;
			$status.show().text( loadingText );
			$aiFeedback.hide();
			$list.empty();
			
			$btn.prop( 'disabled', true );

			var mainSource = sourceVal.startsWith( 'puter' ) ? 'puter' : sourceVal;

			wp.ajax.post( 'cyphex_image_hunter_search', {
				nonce: cyphex_image_hunter_vars.nonce,
				prompt: prompt,
				source: mainSource,
				optimize: optimize
			} ).done( function ( response ) {
				var data = response;
				var aiQuery = data.optimized_query || prompt;

				if ( data.optimized_query && optimize ) {
					$aiFeedback.html( '<span class="cyphex_image_hunter_feedback_icon">✨ AI Interpreted:</span> ' + aiQuery ).show();
				}

				if ( mainSource === 'puter' ) {
					var modelId = 'dall-e-3'; 
					var modelName = 'AI Image';
					var options = {};

					var size = "1024x1024";
					if ( w > 0 && h > 0 ) {
						if ( w > h ) size = "1792x1024"; 
						else if ( h > w ) size = "1024x1792"; 
					}

					if ( sourceVal === 'puter-flux' ) {
						modelId = 'black-forest-labs/FLUX.1-schnell';
						modelName = 'Flux 1.1';
					} else if ( sourceVal === 'puter-sd3' ) {
						modelId = 'stabilityai/stable-diffusion-3-medium';
						modelName = 'SD 3';
					} else {
						modelId = 'dall-e-3';
						modelName = 'DALL-E 3';
						options.quality = 'hd';
					}
					
					if ( modelId === 'dall-e-3' ) {
						options.size = size;
					}

					$status.text( cyphex_image_hunter_vars.labels.statusGenerating + ' ' + modelName + ' ( '+size+' )...' );
					
					if ( typeof puter === 'undefined' ) {
						$status.html( '<span style="color:red">' + cyphex_image_hunter_vars.labels.errorPuter + '</span>' );
						$btn.prop( 'disabled', false ); 
						return;
					}

					var finalPrompt = aiQuery;
					if ( optimize ) {
						finalPrompt += ", raw photo, hyperrealistic, 8k, highly detailed, professional photography, cinematic lighting, shot on 35mm, no illustration";
					}
					
					options.model = modelId;

					puter.ai.txt2img( finalPrompt, options )
						.then( function ( imgElement ) {
							$status.hide();
							$btn.prop( 'disabled', false ); 
							var imgSrc = imgElement.src;
							var tmpl = wp.template( 'cyphex-image-hunter-image-item' );
							var item = {
								src: { original: imgSrc, medium: imgSrc },
								photographer: 'AI ( ' + modelName + ' )',
								source: 'Puter',
								prompt: prompt 
							};
							$list.append( tmpl( item ) );
							self.controller.cyphex_image_hunter_state.resultsHTML = $list.html();
						} )
						.catch( function ( err ) {
							console.error( err );
							$status.html( '<span style="color:red">Generation Failed: ' + ( err.message || 'Unknown Error' ) + '</span>' );
							$btn.prop( 'disabled', false ); 
						} );

				} else {
					$status.hide();
					$btn.prop( 'disabled', false ); 
					var items = data.results || [];
					var tmpl = wp.template( 'cyphex-image-hunter-image-item' );
					if ( items.length === 0 ) {
						$status.show().text( cyphex_image_hunter_vars.labels.errorNoResults );
					} else {
						items.forEach( function ( item ) {
							item.prompt = prompt; 
							$list.append( tmpl( item ) );
						} );
					}
					self.controller.cyphex_image_hunter_state.resultsHTML = $list.html();
				}
			} ).fail( function ( response ) {
				$status.show().html( '<span style="color:#d63638">Error: ' + ( response.data || response ) + '</span>' );
				$btn.prop( 'disabled', false ); 
			} );
		},
		
		refineImage: function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var $el = $( e.currentTarget ).closest( '.cyphex_image_hunter_attachment' );
			var basePrompt = $el.data( 'base-prompt' ) || this.currentPrompt;
			var width = parseInt( this.$( '#cyphex_image_hunter_width' ).val() ) || 0;
			var height = parseInt( this.$( '#cyphex_image_hunter_height' ).val() ) || 0;
			
			var instruction = prompt( cyphex_image_hunter_vars.labels.refinePrompt );
			if ( !instruction ) return;
			
			var $status = this.$( '#cyphex_image_hunter_status' );
			var $aiFeedback = this.$( '#cyphex_image_hunter_ai_feedback' );
			$status.show().text( cyphex_image_hunter_vars.labels.statusRefining );
			
			wp.ajax.post( 'cyphex_image_hunter_refine_prompt', {
				nonce: cyphex_image_hunter_vars.nonce,
				base_prompt: basePrompt,
				instruction: instruction
			} ).done( function ( response ) {
				if ( response.success ) {
					var newPrompt = response.data;
					$aiFeedback.html( '<span class="cyphex_image_hunter_feedback_icon">✨ Refined Prompt:</span> ' + newPrompt ).show();
					
					var currentSource = this.$( '#cyphex_image_hunter_source' ).val();
					if ( !currentSource.startsWith( 'puter' ) ) {
						currentSource = 'puter-flux'; 
						this.$( '#cyphex_image_hunter_source' ).val( currentSource );
					}
					
					this.$( '#cyphex_image_hunter_search_input' ).val( newPrompt );
					this.controller.cyphex_image_hunter_state.prompt = newPrompt; 
					this.runSearch( newPrompt, currentSource, 0, width, height ); 
				} else {
					alert( cyphex_image_hunter_vars.labels.errorRefine );
					$status.hide();
				}
			}.bind( this ) ).fail( function (){
				alert( cyphex_image_hunter_vars.labels.errorServer );
				$status.hide();
			} );
		},

		sideloadImage: function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var $el = $( e.currentTarget ).closest( '.cyphex_image_hunter_attachment' );
			var url = $el.data( 'url' );
			var source = $el.data( 'source' );
			var prompt = $el.data( 'base-prompt' ) || this.currentPrompt;
			var photographer = $el.data( 'photographer' ) || '';
			var link = $el.data( 'link' ) || '';
			
			var width = this.$( '#cyphex_image_hunter_width' ).val();
			var height = this.$( '#cyphex_image_hunter_height' ).val();
			var maxSize = this.$( '#cyphex_image_hunter_size' ).val();
			var convertWebp = this.$( '#cyphex_image_hunter_webp' ).is( ':checked' ) ? 1 : 0;
			var autoCredit = this.$( '#cyphex_image_hunter_credit' ).is( ':checked' ) ? 1 : 0;
			var aiAlt = this.$( '#cyphex_image_hunter_ai_alt' ).is( ':checked' ) ? 1 : 0;
			var aiDesc = this.$( '#cyphex_image_hunter_ai_desc' ).is( ':checked' ) ? 1 : 0;
			var $status = this.$( '#cyphex_image_hunter_status' );

			var msg = cyphex_image_hunter_vars.labels.statusDownloading;
			if ( width && height ) msg += ', Resizing ( '+width+'x'+height+' )';
			if ( convertWebp ) msg += ', Converting to WebP';
			if ( maxSize ) msg += ', Compressing';
			msg += ' & Generating Metadata';
			
			$status.show().text( msg + '...' );
			$el.css( 'opacity', '0.5' );

			var uploadImage = function ( finalUrlOrData ) {
				var controller = this.controller;
				wp.ajax.post( 'cyphex_image_hunter_sideload', {
					nonce: cyphex_image_hunter_vars.nonce,
					image_url: finalUrlOrData,
					source: source,
					width: width,
					height: height,
					max_size_kb: maxSize,
					convert_webp: convertWebp,
					auto_credit: autoCredit,
					ai_alt: aiAlt,
					ai_desc: aiDesc,
					prompt: prompt,
					photographer: photographer,
					link: link
				} ).done( function ( response ) {
					var attachmentId = response.id;
					if ( controller.isModeActive( 'select' ) ) controller.setState( 'insert' );
					var selection = controller.state().get( 'selection' );
					var attachment = wp.media.attachment( attachmentId );
					attachment.fetch();
					selection.reset();
					selection.add( attachment ? [attachment] : [] );
					controller.trigger( 'selection:toggle' );
					$status.hide();
					$el.css( 'opacity', '1' );
				} ).fail( function ( response ) {
					$status.show().text( 'Upload failed: ' + ( response.data || response ) );
					$el.css( 'opacity', '1' );
				} );
			}.bind( this );

			// For AI images (Puter, Together AI, or Blobs), we download in the browser 
			// and send as Base64 to bypass server-side link/redirect restrictions.
			var isAI = ( source === 'Puter' || url.indexOf( 'blob:' ) === 0 || url.includes( 'together.ai' ) || url.includes( 'puter' ) );

			if ( isAI ) {
				$status.show().text( msg + ' (Processing AI Data)...' );
				fetch( url )
					.then( function( r ) { return r.blob(); } )
					.then( function( blob ) {
						var reader = new FileReader();
						reader.onloadend = function() {
							uploadImage( reader.result );
						};
						reader.readAsDataURL( blob );
					} )
					.catch( function( e ) {
						console.error( 'Cyphex: AI transfer failed', e );
						// Fallback to sending the URL if fetch fails
						uploadImage( url );
					} );
			} else {
				// Standard URL (Pexels, etc.)
				uploadImage( url );
			}
		},

		removeBackground: function( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( ! cyphex_image_hunter_vars.isPro ) {
				alert( cyphex_image_hunter_vars.labels.proRequired );
				return;
			}

			var $el = $( e.currentTarget ).closest( '.cyphex_image_hunter_attachment' );
			var url = $el.data( 'url' );
			var $status = this.$( '#cyphex_image_hunter_status' );
			var self = this;

			$status.show().text( cyphex_image_hunter_vars.labels.statusRemovingBg );
			$el.css( 'opacity', '0.5' );

			wp.ajax.post( 'cyphex_image_hunter_remove_bg', {
				nonce: cyphex_image_hunter_vars.nonce,
				image_url: url
			} ).done( function( response ) {
				$status.hide();
				$el.css( 'opacity', '1' );
				if ( response.url ) {
					// Update image in view
					$el.find( 'img' ).attr( 'src', response.url );
					$el.data( 'url', response.url );
					// Update state
					self.controller.cyphex_image_hunter_state.resultsHTML = self.$( '#cyphex_image_hunter_results_list' ).html();
				}
			} ).fail( function( response ) {
				alert( response.data || 'Failed to remove background' );
				$status.hide();
				$el.css( 'opacity', '1' );
			} );
		},

		inpaintImage: function( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( ! cyphex_image_hunter_vars.isPro ) {
				alert( cyphex_image_hunter_vars.labels.proRequired );
				return;
			}
			
			var $el = $( e.currentTarget ).closest( '.cyphex_image_hunter_attachment' );
			var url = $el.data( 'url' );
			var self = this;

			// Initialize Mask Modal
			var tmpl = wp.template( 'cyphex-image-hunter-mask-modal' );
			var $modal = $( tmpl( { url: url } ) );
			$( 'body' ).append( $modal );

			// Initialize Canvas
			var canvas = $modal.find( '#cyphex_mask_canvas' )[0];
			var ctx = canvas.getContext( '2d' );
			var $img = $modal.find( '#cyphex_mask_source_img' );
			
			$img.on( 'load', function() {
				// Set canvas size to match image aspect ratio
				var imgW = this.naturalWidth;
				var imgH = this.naturalHeight;
				var displayW = Math.min( 760, imgW );
				var scale = displayW / imgW;
				var displayH = imgH * scale;
				
				canvas.width = imgW;
				canvas.height = imgH;
				$( canvas ).css( { width: displayW + 'px', height: displayH + 'px' } );
				$( canvas ).css( 'background-image', 'url(' + url + ')' );
				
				// Initialize Drawing
				var drawing = false;
				var lastX, lastY;

				function getPos( e ) {
					var rect = canvas.getBoundingClientRect();
					var scaleX = canvas.width / rect.width;
					var scaleY = canvas.height / rect.height;
					return {
						x: ( ( e.clientX || e.touches[0].clientX ) - rect.left ) * scaleX,
						y: ( ( e.clientY || e.touches[0].clientY ) - rect.top ) * scaleY
					};
				}

				$( canvas ).on( 'mousedown touchstart', function( e ) {
					drawing = true;
					var pos = getPos( e );
					lastX = pos.x;
					lastY = pos.y;
				} );

				$( canvas ).on( 'mousemove touchmove', function( e ) {
					if ( ! drawing ) return;
					e.preventDefault();
					var pos = getPos( e );
					var brushSize = $modal.find( '#cyphex_mask_brush_size' ).val();
					
					ctx.beginPath();
					ctx.moveTo( lastX, lastY );
					ctx.lineTo( pos.x, pos.y );
					ctx.strokeStyle = 'white'; // Mask is white on black
					ctx.lineWidth = brushSize;
					ctx.lineCap = 'round';
					ctx.stroke();
					
					lastX = pos.x;
					lastY = pos.y;
				} );

				$( window ).on( 'mouseup touchend', function() { drawing = false; } );
			} );

			// Modal Actions
			$modal.find( '.cyphex_mask_modal_close' ).on( 'click', function() { $modal.remove(); } );
			$modal.find( '.cyphex_mask_modal_clear' ).on( 'click', function() { ctx.clearRect( 0, 0, canvas.width, canvas.height ); } );
			
			$modal.find( '.cyphex_mask_modal_submit' ).on( 'click', function() {
				var promptText = $modal.find( '#cyphex_mask_prompt' ).val();
				if ( ! promptText ) {
					alert( 'Please enter a prompt' );
					return;
				}

				// Create a final mask (Black background, white strokes)
				var finalCanvas = document.createElement( 'canvas' );
				finalCanvas.width = canvas.width;
				finalCanvas.height = canvas.height;
				var fCtx = finalCanvas.getContext( '2d' );
				fCtx.fillStyle = 'black';
				fCtx.fillRect( 0, 0, finalCanvas.width, finalCanvas.height );
				fCtx.drawImage( canvas, 0, 0 );
				var maskBase64 = finalCanvas.toDataURL( 'image/png' );

				var $status = self.$( '#cyphex_image_hunter_status' );
				$status.show().text( cyphex_image_hunter_vars.labels.statusInpainting );
				$el.css( 'opacity', '0.5' );
				$modal.remove();

				wp.ajax.post( 'cyphex_image_hunter_inpainting', {
					nonce: cyphex_image_hunter_vars.nonce,
					image_url: url,
					mask: maskBase64,
					prompt: promptText
				} ).done( function( response ) {
					$status.hide();
					$el.css( 'opacity', '1' );
					if ( response.url ) {
						$el.find( 'img' ).attr( 'src', response.url );
						$el.data( 'url', response.url );
						self.controller.cyphex_image_hunter_state.resultsHTML = self.$( '#cyphex_image_hunter_results_list' ).html();
					}
				} ).fail( function( response ) {
					alert( response.data || 'Inpainting failed' );
					$status.hide();
					$el.css( 'opacity', '1' );
				} );
			} );
		}
	} );

    // --- 4. Official WordPress Media Modal Extension ---
    // We follow the official WordPress developer guidelines by extending 
    // the specific frame prototypes. This is the recommended way to add 
    // custom tabs to the media library without using hacks or patches.
    
    var extendCyphexMediaFrames = function() {
        if ( typeof wp === 'undefined' || ! wp.media || ! wp.media.view.MediaFrame ) {
            return;
        }

        // Standard frames that support a browse router (tabs)
        var FrameClasses = [
            wp.media.view.MediaFrame.Post,
            wp.media.view.MediaFrame.Select,
            wp.media.view.MediaFrame.Manage,
            wp.media.view.MediaFrame.ImageDetails,
            wp.media.view.MediaFrame.Gallery
        ];

        _.each( FrameClasses, function( FrameClass ) {
            if ( ! FrameClass || ! FrameClass.prototype ) {
                return;
            }

            // A. Extend the Router (The Tab)
            var originalBrowseRouter = FrameClass.prototype.browseRouter;
            if ( typeof originalBrowseRouter === 'function' ) {
                FrameClass.prototype.browseRouter = function( routerView ) {
                    // Call the original method to preserve standard tabs
                    originalBrowseRouter.apply( this, arguments );

                    if ( routerView ) {
                        routerView.set( 'image_search', {
                            text: ( typeof cyphex_image_hunter_vars !== 'undefined' ) ? cyphex_image_hunter_vars.labels.hunt : 'Cyphex Image Hunt',
                            priority: 60
                        } );
                    }
                };
            }

            // B. Extend Bind Handlers (The Logic)
            var originalBindHandlers = FrameClass.prototype.bindHandlers;
            if ( typeof originalBindHandlers === 'function' ) {
                FrameClass.prototype.bindHandlers = function() {
                    // Call original handlers first
                    originalBindHandlers.apply( this, arguments );

                    // Bind our specific content creator
                    this.on( 'content:create:image_search', this.cyphexRenderContent, this );
                };
            }

            // C. Define the Render Callback on the prototype
            FrameClass.prototype.cyphexRenderContent = function( contentRegion ) {
                try {
                    contentRegion.view = new Image_Hunter_View( {
                        controller: this
                    } );
                } catch ( e ) {
                    // Fail gracefully without crashing the whole modal
                    console.error( 'Cyphex: Rendering failed', e );
                }
            };
        } );
    };

    // Initialize using the recommended WordPress pattern
    try {
        extendCyphexMediaFrames();
    } catch ( e ) {
        console.error( 'Cyphex: Initialization failed', e );
    }

	// --- 5. Media Library Sidebar Extension (Direct Functions) ---
	var extendMediaSidebar = function() {
		if ( typeof wp === 'undefined' || ! wp.media ) return;

		// We extend both standard and two-column views
		var SidebarViews = [
			wp.media.view.Attachment.Details,
			wp.media.view.Attachment.Details.TwoColumn
		];

		_.each( SidebarViews, function( ViewClass ) {
			if ( ! ViewClass || ! ViewClass.prototype ) return;

			var originalRender = ViewClass.prototype.render;
			ViewClass.prototype.render = function() {
				originalRender.apply( this, arguments );

				var attachment = this.model.toJSON();
				// Only show for images
				if ( attachment.type !== 'image' ) return;

				var isPro = cyphex_image_hunter_vars.isPro;
				var buttonsHtml = `
					<div class="cyphex-sidebar-actions" style="margin-top: 15px; border-top: 1px solid #ddd; padding-top: 15px;">
						<label class="setting">
							<span class="name">Cyphex AI</span>
							<div class="value" style="display: flex; flex-wrap: wrap; gap: 5px;">
								<button type="button" class="button button-secondary cyphex-sidebar-btn-remove-bg ${ !isPro ? 'disabled' : '' }" data-id="${attachment.id}" data-url="${attachment.url}">
									${cyphex_image_hunter_vars.labels.removeBg} ${ !isPro ? '🔒' : '' }
								</button>
								<button type="button" class="button button-secondary cyphex-sidebar-btn-ai-alt ${ !isPro ? 'disabled' : '' }" data-id="${attachment.id}" data-url="${attachment.url}">
									${cyphex_image_hunter_vars.labels.aiAlt} ${ !isPro ? '🔒' : '' }
								</button>
								<button type="button" class="button button-secondary cyphex-sidebar-btn-webp ${ !isPro ? 'disabled' : '' }" data-id="${attachment.id}">
									${cyphex_image_hunter_vars.labels.webp} ${ !isPro ? '🔒' : '' }
								</button>
								<p class="description" style="width: 100%; margin-top: 5px;">${ !isPro ? cyphex_image_hunter_vars.labels.proRequired : 'Uses your BYOAPI (Replicate)' }</p>
							</div>
						</label>
					</div>
				`;

				if ( this.$el.find( '.cyphex-sidebar-actions' ).length === 0 ) {
					this.$el.find( '.settings' ).append( buttonsHtml );
				}
				return this;
			};
		} );

		// Handle Sidebar Button Clicks
		$( document ).on( 'click', '.cyphex-sidebar-btn-remove-bg', function( e ) {
			e.preventDefault();
			if ( $( this ).hasClass( 'disabled' ) ) {
				alert( cyphex_image_hunter_vars.labels.proRequired );
				return;
			}

			var $btn = $( this );
			var id = $btn.data( 'id' );
			var url = $btn.data( 'url' );
			var $container = $btn.closest( '.value' );
			
			$btn.prop( 'disabled', true ).text( '...' );
			$container.find( '.description' ).text( cyphex_image_hunter_vars.labels.statusRemovingBg );

			wp.ajax.post( 'cyphex_image_hunter_remove_bg', {
				nonce: cyphex_image_hunter_vars.nonce,
				image_url: url
			} ).done( function( response ) {
				if ( response.url ) {
					// In a real scenario, we might want to replace the file or create a new one.
					// For now, we provide the link and suggest downloading/replacing.
					$container.find( '.description' ).html( 'Success! <a href="' + response.url + '" target="_blank">View Result</a>' );
					$btn.text( 'Done' );
				}
			} ).fail( function( response ) {
				$container.find( '.description' ).text( 'Error: ' + ( response.data || 'Failed' ) );
				$btn.prop( 'disabled', false ).text( cyphex_image_hunter_vars.labels.removeBg );
			} );
		} );

		$( document ).on( 'click', '.cyphex-sidebar-btn-ai-alt', function( e ) {
			e.preventDefault();
			if ( $( this ).hasClass( 'disabled' ) ) {
				alert( cyphex_image_hunter_vars.labels.proRequired );
				return;
			}

			var $btn = $( this );
			var id = $btn.data( 'id' );
			var url = $btn.data( 'url' );
			var $container = $btn.closest( '.value' );
			
			$btn.prop( 'disabled', true ).text( '...' );
			$container.find( '.description' ).text( 'AI Analyzing...' );

			wp.ajax.post( 'cyphex_image_hunter_ai_alt_text', {
				nonce: cyphex_image_hunter_vars.nonce,
				attachment_id: id,
				image_url: url
			} ).done( function( response ) {
				$container.find( '.description' ).html( 'Success! Alt: ' + response.alt );
				$btn.text( 'Done' );
				// Update native fields if possible
				$( 'textarea[data-setting="alt"]' ).val( response.alt );
				$( 'textarea[data-setting="description"]' ).val( response.desc );
			} ).fail( function( response ) {
				$container.find( '.description' ).text( 'Error: ' + ( response.data || 'Failed' ) );
				$btn.prop( 'disabled', false ).text( cyphex_image_hunter_vars.labels.aiAlt );
			} );
		} );
		$( document ).on( 'click', '.cyphex-sidebar-btn-webp', function( e ) {
			e.preventDefault();
			if ( $( this ).hasClass( 'disabled' ) ) {
				alert( cyphex_image_hunter_vars.labels.proRequired );
				return;
			}

			var $btn = $( this );
			var id = $btn.data( 'id' );
			var $container = $btn.closest( '.value' );
			
			$btn.prop( 'disabled', true ).text( '...' );
			$container.find( '.description' ).text( 'Optimizing...' );

			wp.ajax.post( 'cyphex_bulk_webp_process', {
				nonce: cyphex_image_hunter_vars.nonce,
				attachment_id: id
			} ).done( function( response ) {
				$container.find( '.description' ).text( 'Optimized to WebP!' );
				$btn.text( 'Done' );
			} ).fail( function( response ) {
				$container.find( '.description' ).text( 'Error: ' + ( response.data || 'Failed' ) );
				$btn.prop( 'disabled', false ).text( cyphex_image_hunter_vars.labels.webp );
			} );
		} );
	};

	try {
		extendMediaSidebar();
	} catch ( e ) {
		console.error( 'Cyphex: Sidebar extension failed', e );
	}

	// --- 6. License Activation Logic ---
	$( '#cyphex_activate_license_btn' ).on( 'click', function( e ) {
		e.preventDefault();
		var $btn = $( this );
		var key = $( '#cyphex_license_key_field' ).val();

		if ( ! key ) {
			alert( 'Please enter a license key.' );
			return;
		}

		$btn.prop( 'disabled', true ).text( 'Activating...' );

		wp.ajax.post( 'cyphex_activate_license', {
			nonce: cyphex_image_hunter_vars.nonce,
			license_key: key
		} ).done( function( response ) {
			alert( response.message );
			location.reload();
		} ).fail( function( response ) {
			alert( response.data || 'Activation failed.' );
			$btn.prop( 'disabled', false ).text( 'Activate License' );
		} );
	} );

	$( '#cyphex_deactivate_license_btn' ).on( 'click', function( e ) {
		e.preventDefault();
		if ( ! confirm( 'Are you sure you want to deactivate your license?' ) ) return;

		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Deactivating...' );

		wp.ajax.post( 'cyphex_deactivate_license', {
			nonce: cyphex_image_hunter_vars.nonce
		} ).done( function( response ) {
			alert( response.message );
			location.reload();
		} ).fail( function( response ) {
			alert( 'Deactivation failed.' );
			$btn.prop( 'disabled', false ).text( 'Deactivate License' );
		} );
	} );

	// --- 7. Bulk WebP Logic ---
	$( '#doaction, #doaction2' ).on( 'click', function( e ) {
		var $selector = $( this ).siblings( 'select' );
		if ( $selector.val() !== 'cyphex_bulk_webp' ) return;

		e.preventDefault();

		var selectedIds = [];
		$( 'tbody th.check-column input[type="checkbox"]:checked' ).each( function() {
			selectedIds.push( $( this ).val() );
		} );

		if ( selectedIds.length === 0 ) {
			alert( 'Please select at least one image.' );
			return;
		}

		// Initialize Modal
		var $modal = $( '#cyphex-bulk-progress-modal' );
		$modal.show();
		
		var total = selectedIds.length;
		var current = 0;
		var startTime = Date.now();

		function processNext() {
			if ( current >= total ) {
				$( '#cyphex-bulk-progress-title' ).text( 'Conversion Complete!' );
				$( '#cyphex-bulk-progress-timer' ).text( 'Finished ' + total + ' images.' );
				setTimeout( function() { location.reload(); }, 1500 );
				return;
			}

			var id = selectedIds[current];
			var progress = Math.round( ( current / total ) * 100 );
			
			$( '#cyphex-bulk-progress-bar-inner' ).css( 'width', progress + '%' );
			$( '#cyphex-bulk-progress-count' ).text( ( current + 1 ) + ' / ' + total );

			// Estimate time
			if ( current > 0 ) {
				var elapsed = Date.now() - startTime;
				var perItem = elapsed / current;
				var remaining = Math.round( ( ( total - current ) * perItem ) / 1000 );
				$( '#cyphex-bulk-progress-timer' ).text( 'Est. remaining: ' + remaining + 's' );
			}

			wp.ajax.post( 'cyphex_bulk_webp_process', {
				nonce: cyphex_image_hunter_vars.nonce,
				attachment_id: id
			} ).always( function() {
				current++;
				processNext();
			} );
		}

		processNext();
	} );
} );
