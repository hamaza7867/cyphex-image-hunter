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
} );
